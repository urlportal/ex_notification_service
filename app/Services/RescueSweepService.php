<?php

namespace App\Services;

use App\Messaging\Outbox\MsgOutboxEntry;
use App\Messaging\Outbox\MsgOutboxRepositoryInterface;
use App\Models\Notification;
use App\Repositories\NotificationRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

class RescueSweepService
{
    private const ACTION_REQUEUED = 'requeued';

    private const ACTION_DROPPED = 'dropped';

    public function __construct(
        private readonly NotificationRepositoryInterface $notificationRepo,
        private readonly MsgOutboxRepositoryInterface $outboxRepo,
        private readonly LoggerInterface $logger,
    ) {}

    public function sweep(int $thresholdMinutes, int $batchSize, int $maxProcess): RescueSweepResult
    {
        $result = new RescueSweepResult;
        $stuckBefore = now()->subMinutes($thresholdMinutes)->toDateTimeImmutable();
        $retryExchange = (string) config('rabbitmq.retry.exchange');
        $maxAttempts = (int) config('notifications.retry.max_attempts');
        $levels = (array) config('notifications.retry.levels');

        // Упавшие в этом запуске записи исключаются из следующих батчей, иначе они
        // снова окажутся первыми в выборке и займут лимит запуска
        $failedIds = [];
        $stoppedByLimit = false;

        while ($result->found < $maxProcess) {
            $limit = min($batchSize, $maxProcess - $result->found);

            $hasBatch = DB::transaction(function () use ($stuckBefore, $limit, $retryExchange, $maxAttempts, $levels, $result, &$failedIds) {
                $notifications = $this->notificationRepo->findStuckBatchForUpdate($stuckBefore, $limit, $failedIds);

                if ($notifications->isEmpty()) {
                    return false;
                }

                foreach ($notifications as $notification) {
                    $result->found++;

                    try {
                        // Вложенная транзакция создаёт точку сохранения: сбой откатывает
                        // изменения только этого уведомления, остальные в батче сохраняются
                        $action = DB::transaction(
                            fn () => $this->rescueOne($notification, $retryExchange, $maxAttempts, $levels),
                        );
                    } catch (\Throwable $exception) {
                        $failedIds[] = (string) $notification->id;
                        $result->failed++;

                        $this->logger->error('rescue sweep: ошибка обработки уведомления', [
                            'notification_id' => (string) $notification->id,
                            'request_id' => (string) ($notification->request_id ?? ''),
                            'attempts' => (int) $notification->attempts,
                            'exception' => $exception,
                        ]);

                        continue;
                    }

                    if ($action === self::ACTION_REQUEUED) {
                        $result->requeued++;
                    } else {
                        $result->dropped++;
                    }
                }

                return true;
            });

            if ($hasBatch === false) {
                break;
            }

            if ($result->found >= $maxProcess) {
                $stoppedByLimit = true;
                break;
            }
        }

        // Если цикл остановился из-за лимита maxProcess, считаем оставшиеся stuck-записи
        $result->skipped = $stoppedByLimit
            ? $this->notificationRepo->countStuck($stuckBefore)
            : 0;

        $this->logger->info('rescue sweep: завершён', [
            'found' => $result->found,
            'requeued' => $result->requeued,
            'dropped' => $result->dropped,
            'failed' => $result->failed,
            'skipped' => $result->skipped,
            'threshold_minutes' => $thresholdMinutes,
        ]);

        return $result;
    }

    /**
     * Возвращает уведомление в retry-очередь или финализирует его через dropped.
     *
     * @param  array<int, array{key: string, ttl_ms: int}>  $levels
     * @return self::ACTION_*
     */
    private function rescueOne(Notification $notification, string $retryExchange, int $maxAttempts, array $levels): string
    {
        $notificationId = (string) $notification->id;
        $requestId = (string) ($notification->request_id ?? '');
        $attempts = (int) $notification->attempts;

        if ($attempts >= $maxAttempts || ! isset($levels[$attempts])) {
            // Попытки исчерпаны или номер попытки вне диапазона - финализируем
            $this->notificationRepo->updateOnDropped(
                id: $notificationId,
                droppedAt: now()->toDateTimeImmutable(),
                lastError: (string) ($notification->last_error ?? 'rescue sweep: attempts exhausted'),
                attempts: $attempts,
            );

            $this->logger->warning('rescue sweep: уведомление переведено в dropped', [
                'notification_id' => $notificationId,
                'request_id' => $requestId,
                'attempts' => $attempts,
                'max_attempts' => $maxAttempts,
                'action' => self::ACTION_DROPPED,
            ]);

            return self::ACTION_DROPPED;
        }

        // Постановка на тот же retry-уровень, что соответствует текущему числу попыток
        $retryLevel = (string) $levels[$attempts]['key'];
        $routingKey = $retryLevel.'.'.($notification->delivery_profile ?? '');

        $this->outboxRepo->enqueue(new MsgOutboxEntry(
            id: Uuid::uuid7()->toString(),
            exchange: $retryExchange,
            routingKey: $routingKey,
            payload: ['notification_id' => $notificationId],
            headers: [
                'request_id' => $requestId,
                'retry_attempt' => $attempts,
            ],
        ));

        // Обновляем updated_at, чтобы следующий sweep пропустил эту запись
        $this->notificationRepo->touchForRescue($notificationId);

        // Уровень warning: потеря сообщения из retry-очереди означает инфраструктурный сбой
        $this->logger->warning('rescue sweep: уведомление возвращено в очередь', [
            'notification_id' => $notificationId,
            'request_id' => $requestId,
            'attempts' => $attempts,
            'action' => self::ACTION_REQUEUED,
            'retry_level' => $retryLevel,
            'routing_key' => $routingKey,
        ]);

        return self::ACTION_REQUEUED;
    }
}
