<?php

namespace App\Services;

use App\Enums\NotificationStatus;
use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\SubscribersNotFoundException;
use App\Http\DTO\Notifications\BulkSendNotificationDto;
use App\Logging\RequestIdContext;
use App\Messaging\NotificationPublisherInterface;
use App\Models\Notification;
use App\Repositories\NotificationRepositoryInterface;
use App\Repositories\SubscriberRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

class BulkNotificationService
{
    public function __construct(
        private readonly RequestIdContext $requestIdContext,
        private readonly IdempotencyService $idempotencyService,
        private readonly SubscriberRepositoryInterface $subscriberRepository,
        private readonly NotificationRepositoryInterface $notificationRepository,
        private readonly NotificationPublisherInterface $notificationPublisher,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return array{bulk_request_id: string, accepted: int, notification_ids: array<int, string>}
     */
    public function handle(BulkSendNotificationDto $dto): array
    {
        $requestId = $this->requestIdContext->get() ?? Uuid::uuid7()->toString();
        $ttlSeconds = $this->idempotencyService->getDefaultTtl();

        /** @var array{bulk_request_id: string, accepted: int, notification_ids: array<int, string>}|null $cached */
        $cached = $this->idempotencyService->getResponse($dto->idempotencyKey);
        if ($cached !== null) {
            return $cached;
        }

        $acquired = $this->idempotencyService->acquire($dto->idempotencyKey, $ttlSeconds);
        if (! $acquired) {
            /** @var array{bulk_request_id: string, accepted: int, notification_ids: array<int, string>}|null $retried */
            $retried = $this->idempotencyService->getResponse($dto->idempotencyKey);
            if ($retried !== null) {
                return $retried;
            }
            throw new IdempotencyConflictException;
        }

        $existingIds = $this->subscriberRepository->findExistingIds($dto->subscriberIds);
        $missingIds = array_values(array_diff($dto->subscriberIds, $existingIds));
        if (! empty($missingIds)) {
            throw new SubscribersNotFoundException($missingIds);
        }

        $bulkRequestId = Uuid::uuid7()->toString();
        $now = now()->toDateTimeString();
        $rows = [];
        $notificationIds = [];

        foreach ($dto->subscriberIds as $subscriberId) {
            $id = Uuid::uuid7()->toString();
            $notificationIds[] = $id;
            $rows[] = [
                'id' => $id,
                'subscriber_id' => $subscriberId,
                'bulk_request_id' => $bulkRequestId,
                'channel' => $dto->channel->value,
                'priority' => $dto->priority->value,
                'status' => NotificationStatus::Queued->value,
                'message' => $dto->message,
                'idempotency_key' => $dto->idempotencyKey,
                'request_id' => $requestId,
                'queued_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::transaction(function () use ($rows): void {
            $this->notificationRepository->createBulk($rows);
        });

        foreach ($rows as $row) {
            $notification = new Notification(['id' => $row['id'], 'priority' => $dto->priority->value]);
            try {
                $this->notificationPublisher->publishForInitialSend($notification, $requestId);
            } catch (\Throwable $e) {
                $this->logger->error('Ошибка публикации уведомления в RabbitMQ', [
                    'notification_id' => $row['id'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $response = [
            'bulk_request_id' => $bulkRequestId,
            'accepted' => count($notificationIds),
            'notification_ids' => $notificationIds,
        ];

        $this->idempotencyService->storeResponse($dto->idempotencyKey, $response, $ttlSeconds);

        return $response;
    }
}
