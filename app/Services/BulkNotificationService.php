<?php

namespace App\Services;

use App\Exceptions\IdempotencyConflictException;
use App\Http\DTO\Notifications\BulkAcceptedResponse;
use App\Http\DTO\Notifications\BulkSendNotificationDto;
use App\Logging\RequestIdContext;
use App\Messaging\Outbox\MsgOutboxEntry;
use App\Messaging\Outbox\MsgOutboxRepositoryInterface;
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
        private readonly MsgOutboxRepositoryInterface $outboxRepository,
        private readonly LoggerInterface $logger,
    ) {}

    public function handle(BulkSendNotificationDto $dto): BulkAcceptedResponse
    {
        $requestId = $this->requestIdContext->get() ?? Uuid::uuid7()->toString();
        $ttlSeconds = $this->idempotencyService->getDefaultTtl();

        $cached = $this->idempotencyService->getResponse($dto->idempotencyKey);
        if ($cached !== null) {
            return $this->fromCache($cached);
        }

        $acquired = $this->idempotencyService->acquire($dto->idempotencyKey, $ttlSeconds);
        if (! $acquired) {
            $retried = $this->idempotencyService->getResponse($dto->idempotencyKey);
            if ($retried !== null) {
                return $this->fromCache($retried);
            }
            throw new IdempotencyConflictException;
        }

        // Tolerant ingest: один SELECT по индексу, затем фильтрация исходного
        // списка с сохранением порядка запроса (findExistingIds порядок не гарантирует).
        $existingSet = array_flip($this->subscriberRepository->findExistingIds($dto->subscriberIds));
        $acceptedIds = [];
        $invalidIds = [];
        foreach ($dto->subscriberIds as $subscriberId) {
            if (isset($existingSet[$subscriberId])) {
                $acceptedIds[] = $subscriberId;
            } else {
                $invalidIds[] = $subscriberId;
            }
        }

        $bulkRequestId = Uuid::uuid7()->toString();
        $outboxId = null;

        if ($acceptedIds !== []) {
            $outboxId = Uuid::uuid7()->toString();
            $payload = [
                'bulk_request_id' => $bulkRequestId,
                'channel' => $dto->channel->value,
                'priority' => $dto->priority->value,
                'message' => $dto->message,
                'subscriber_ids' => $acceptedIds,
                'idempotency_key' => $dto->idempotencyKey,
            ];
            $headers = ['request_id' => $requestId, 'retry_attempt' => 0];

            DB::transaction(function () use ($outboxId, $payload, $headers): void {
                $this->outboxRepository->enqueue(new MsgOutboxEntry(
                    id: $outboxId,
                    exchange: config('rabbitmq.bulk_expand.exchange'),
                    routingKey: config('rabbitmq.bulk_expand.routing_key'),
                    payload: $payload,
                    headers: $headers,
                ));
            });
        }

        $response = new BulkAcceptedResponse(
            bulkRequestId: $bulkRequestId,
            accepted: count($acceptedIds),
            invalidSubscriberIds: $invalidIds,
        );

        // Точка трассировки в начале асинхронной цепочки HTTP → outbox → relay → брокер.
        $this->logger->info('bulk notification accepted', [
            'request_id' => $requestId,
            'bulk_request_id' => $bulkRequestId,
            'outbox_id' => $outboxId,
            'accepted' => $response->accepted,
            'invalid_count' => count($invalidIds),
        ]);

        $this->idempotencyService->storeResponse($dto->idempotencyKey, $response->toArray(), $ttlSeconds);

        return $response;
    }

    /**
     * Восстанавливает DTO ответа из закэшированного snake_case-массива,
     * чтобы повторный запрос отдал идентичный JSON.
     *
     * @param  array<string, mixed>  $cached
     */
    private function fromCache(array $cached): BulkAcceptedResponse
    {
        /** @var list<string> $invalid */
        $invalid = is_array($cached['invalid_subscriber_ids'] ?? null) ? array_values($cached['invalid_subscriber_ids']) : [];

        return new BulkAcceptedResponse(
            bulkRequestId: (string) $cached['bulk_request_id'],
            accepted: (int) $cached['accepted'],
            invalidSubscriberIds: $invalid,
        );
    }
}
