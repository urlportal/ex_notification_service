<?php

namespace Tests\Feature\Messaging\Retry;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PhpAmqpLib\Message\AMQPMessage;
use Tests\Feature\Messaging\Sender\SenderTestCase;

/**
 * Базовый класс для Feature-тестов retry-механизма.
 *
 * Расширяет SenderTestCase утилитами:
 *   - runOutboxRelayOnce(): прогон одной итерации messaging:outbox-relay;
 *   - waitForRetryReturnAndConsume(): publish retry-копии в брокер + ожидание её
 *     возврата в основную приоритетную очередь по TTL + consume;
 *   - assertMsgOutboxRetryCopy(): проверка существования и атрибутов
 *     retry-копии в msg_outbox по notification_id;
 *   - assertMsgOutboxRetryCopyCount(): подсчёт retry-копий по notification_id.
 */
abstract class RetryTestCase extends SenderTestCase
{
    protected function runOutboxRelayOnce(): void
    {
        $exitCode = Artisan::call('messaging:outbox-relay', [
            '--batch-size' => 100,
            '--max-iterations' => 1,
        ]);
        $this->assertSame(0, $exitCode);
    }

    /**
     * Опубликовать pending retry-копии из msg_outbox в брокер и дождаться,
     * пока retry-сообщение через TTL-задержку retry-очереди вернётся в
     * основную приоритетную очередь, после чего прогнать sender.
     *
     * Шаги:
     *   1) Прогон relay один раз: переносит pending-строки msg_outbox в
     *      RabbitMQ (в тестах supervisord-relay может быть приостановлен).
     *   2) Polling basic_get на $queueName с шагом 50 мс до тайм-аута
     *      $pollTimeoutMs. В тестовой топологии TTL retry-очередей 100 мс,
     *      возврат ожидается за сотни миллисекунд.
     *   3) После появления сообщения - вернуть его в очередь (`basic_get`
     *      с no_ack=false + basic_reject(requeue=true)) и прогнать
     *      notifications:send на $expectedMessages.
     */
    protected function waitForRetryReturnAndConsume(
        string $queueName,
        int $expectedMessages,
        int $pollTimeoutMs = 5000,
    ): void {
        $this->runOutboxRelayOnce();

        $deadline = microtime(true) + ($pollTimeoutMs / 1000.0);
        $channel = $this->amqpFactory->connection()->channel();
        try {
            while (microtime(true) < $deadline) {
                $message = $channel->basic_get($queueName, no_ack: false);
                if ($message !== null) {
                    // Возвращаем сообщение обратно в очередь, чтобы его
                    // обработал штатный consumer внутри consume(...).
                    $channel->basic_reject($message->getDeliveryTag(), requeue: true);
                    break;
                }
                usleep(50_000);
            }
        } finally {
            $channel->close();
        }

        if (microtime(true) >= $deadline) {
            $this->fail(sprintf(
                'retry message did not return to %s within %d ms',
                $queueName,
                $pollTimeoutMs,
            ));
        }

        $profile = match ($queueName) {
            self::QUEUE_TRANSACTIONAL => 'transactional',
            self::QUEUE_MARKETING => 'marketing',
            default => $this->fail(sprintf('unsupported queue name %s', $queueName)),
        };

        $this->consume($profile, $expectedMessages);
    }

    /**
     * Проверяет, что в msg_outbox есть одна (или N) retry-копий для
     * данного notification_id с указанным routing_key и базовыми инвариантами
     * (exchange = notifications.retry.exchange, payload содержит notification_id).
     *
     * Возвращает первую найденную строку для дальнейших ассертов вызывающим кодом.
     *
     * @return array<string, mixed>
     */
    protected function assertMsgOutboxRetryCopy(
        string $notificationId,
        string $expectedRoutingKey,
    ): array {
        $rows = DB::table('msg_outbox')
            ->where('exchange', self::RETRY_EXCHANGE)
            ->where('routing_key', $expectedRoutingKey)
            ->whereRaw("payload->>'notification_id' = ?", [$notificationId])
            ->orderBy('id')
            ->get()
            ->all();

        $this->assertNotEmpty(
            $rows,
            sprintf(
                'expected at least one retry copy in msg_outbox for %s with routing_key=%s',
                $notificationId,
                $expectedRoutingKey,
            ),
        );

        return (array) $rows[0];
    }

    /**
     * Публикует pending retry-копии из msg_outbox в брокер, затем
     * немедленно переносит сообщение из retry-очереди в основную, минуя
     * ожидание TTL. Используется для тестов с уровнями 5m и 30m, где
     * реальная задержка TTL недопустима.
     */
    protected function drainRetryQueueAndConsume(
        string $retryQueueName,
        string $mainQueueName,
        int $expectedMessages,
    ): void {
        $this->runOutboxRelayOnce();

        $channel = $this->amqpFactory->connection()->channel();
        try {
            $message = $channel->basic_get($retryQueueName, no_ack: true);
            if ($message === null) {
                $this->fail(sprintf('в retry-очереди %s нет сообщения', $retryQueueName));
            }
            $channel->basic_publish(
                new AMQPMessage($message->getBody(), $message->get_properties()),
                '',
                $mainQueueName,
            );
        } finally {
            $channel->close();
        }

        $profile = match ($mainQueueName) {
            self::QUEUE_TRANSACTIONAL => 'transactional',
            self::QUEUE_MARKETING => 'marketing',
            default => $this->fail(sprintf('неизвестная очередь %s', $mainQueueName)),
        };

        $this->consume($profile, $expectedMessages);
    }

    /**
     * Считает retry-копии в msg_outbox по notification_id (по всем routing_key retry-exchange).
     */
    protected function countMsgOutboxRetryCopies(string $notificationId): int
    {
        return DB::table('msg_outbox')
            ->where('exchange', self::RETRY_EXCHANGE)
            ->whereRaw("payload->>'notification_id' = ?", [$notificationId])
            ->count();
    }
}
