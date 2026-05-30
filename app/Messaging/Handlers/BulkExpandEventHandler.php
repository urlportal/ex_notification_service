<?php

namespace App\Messaging\Handlers;

use App\Enums\NotificationStatus;
use App\Exceptions\Messaging\InvalidBulkExpandPayloadException;
use App\Logging\RequestIdContext;
use App\Messaging\AbstractIdempotentMessageHandler;
use App\Messaging\DeliveryProfile;
use App\Messaging\Inbox\MsgInboxRepositoryInterface;
use App\Messaging\Messages\BulkExpandMessage;
use App\Messaging\Outbox\MsgOutboxEntry;
use App\Messaging\Outbox\MsgOutboxRepositoryInterface;
use App\Repositories\NotificationRepositoryInterface;
use App\Repositories\SubscriberRepositoryInterface;
use Illuminate\Database\DatabaseManager;
use PhpAmqpLib\Message\AMQPMessage;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

class BulkExpandEventHandler extends AbstractIdempotentMessageHandler
{
    public function __construct(
        MsgInboxRepositoryInterface $inbox,
        DatabaseManager $db,
        LoggerInterface $logger,
        RequestIdContext $requestIdContext,
        private readonly SubscriberRepositoryInterface $subscriberRepo,
        private readonly NotificationRepositoryInterface $notificationRepo,
        private readonly MsgOutboxRepositoryInterface $outboxRepo,
        private readonly DeliveryProfile $deliveryProfile,
    ) {
        parent::__construct($inbox, $db, $logger, $requestIdContext);
    }

    protected function consumerName(): string
    {
        return 'bulk-expand-consumer';
    }

    protected function handleInternal(AMQPMessage $message, string $messageId, array $headers): void
    {
        // fromArray бросит исключение, при невалидном JSON
        $event = BulkExpandMessage::fromArray(json_decode($message->getBody(), true));

        // Имя типа рассылки проверяем здесь, а не в DTO. Сообщение с неизвестным priority уходит в DLQ.
        if (! $this->deliveryProfile->has($event->priority)) {
            throw new InvalidBulkExpandPayloadException("unknown priority: {$event->priority}");
        }

        // Пропускаем только существующих подписчиков
        $existing = array_flip($this->subscriberRepo->findExistingIds($event->subscriberIds));
        $acceptedIds = array_values(array_filter(
            $event->subscriberIds,
            static fn (string $id): bool => isset($existing[$id]),
        ));

        $requestId = is_string($headers['request_id'] ?? null) ? $headers['request_id'] : null;
        $chunkSize = max(1, (int) config('notifications.bulk_expand.chunk_size'));
        $sendExchange = (string) config('rabbitmq.exchange');
        $createdCount = 0;
        $chunkCount = 0;

        // Инсерты выполняются внутри транзакции, открытой базовым классом.
        foreach (array_chunk($acceptedIds, $chunkSize) as $chunk) {
            $now = now();
            $rows = array_map(fn (string $sid): array => [
                'id' => Uuid::uuid7()->toString(),
                'subscriber_id' => $sid,
                'bulk_request_id' => $event->bulkRequestId,
                'channel' => $event->channel->value,
                'priority' => $event->priority,
                'status' => NotificationStatus::Queued->value,
                'message' => $event->message,
                'idempotency_key' => $event->idempotencyKey,
                'request_id' => $requestId,
                'queued_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ], $chunk);

            $idBySubscriber = $this->notificationRepo->upsertBulkAndReturnIds($rows);

            $entries = [];
            foreach ($chunk as $sid) {
                $entries[] = new MsgOutboxEntry(
                    id: Uuid::uuid7()->toString(),
                    exchange: $sendExchange,
                    routingKey: $event->priority,
                    payload: ['notification_id' => $idBySubscriber[$sid]],
                    headers: ['request_id' => $requestId, 'retry_attempt' => 0],
                );
            }

            $this->outboxRepo->enqueueMany(...$entries);

            $createdCount += count($chunk);
            $chunkCount++;
        }

        $this->logger->info('bulk expand event processed', [
            'request_id' => $requestId,
            'bulk_request_id' => $event->bulkRequestId,
            'message_id' => $messageId,
            'created_count' => $createdCount,
            'skipped_count' => count($event->subscriberIds) - $createdCount,
            'chunk_count' => $chunkCount,
        ]);
    }
}
