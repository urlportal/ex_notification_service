<?php

namespace App\Messaging\Messages;

use App\Enums\NotificationChannel;
use App\Exceptions\Messaging\InvalidBulkExpandPayloadException;

readonly class BulkExpandMessage
{
    /** @param list<string> $subscriberIds */
    public function __construct(
        public string $bulkRequestId,
        public NotificationChannel $channel,
        public string $deliveryProfile,
        public string $message,
        public array $subscriberIds,
        public string $idempotencyKey,
    ) {}

    /** @param mixed $payload результат json_decode(..., true) */
    public static function fromArray(mixed $payload): self
    {
        if (! is_array($payload)) {
            throw new InvalidBulkExpandPayloadException('payload is not a JSON object');
        }

        foreach (['bulk_request_id', 'channel', 'delivery_profile', 'message', 'subscriber_ids', 'idempotency_key'] as $key) {
            if (! array_key_exists($key, $payload)) {
                throw new InvalidBulkExpandPayloadException("missing required key: {$key}");
            }
        }

        if (! is_string($payload['bulk_request_id']) || $payload['bulk_request_id'] === '') {
            throw new InvalidBulkExpandPayloadException('bulk_request_id must be a non-empty string');
        }

        if (! is_string($payload['channel'])) {
            throw new InvalidBulkExpandPayloadException('channel must be a string');
        }

        $channel = NotificationChannel::tryFrom($payload['channel'])
            ?? throw new InvalidBulkExpandPayloadException("unknown channel: {$payload['channel']}");

        // Тип delivery_profile проверяется здесь; валидация имени по каталогу - в handler.
        if (! is_string($payload['delivery_profile']) || $payload['delivery_profile'] === '') {
            throw new InvalidBulkExpandPayloadException('delivery_profile must be a non-empty string');
        }

        if (! is_string($payload['message']) || $payload['message'] === '') {
            throw new InvalidBulkExpandPayloadException('message must be a non-empty string');
        }

        if (! is_array($payload['subscriber_ids'])) {
            throw new InvalidBulkExpandPayloadException('subscriber_ids must be an array');
        }

        foreach ($payload['subscriber_ids'] as $index => $subscriberId) {
            if (! is_string($subscriberId)) {
                throw new InvalidBulkExpandPayloadException("subscriber_ids[{$index}] must be a string");
            }
        }

        if (! is_string($payload['idempotency_key']) || $payload['idempotency_key'] === '') {
            throw new InvalidBulkExpandPayloadException('idempotency_key must be a non-empty string');
        }

        return new self(
            bulkRequestId: $payload['bulk_request_id'],
            channel: $channel,
            deliveryProfile: $payload['delivery_profile'],
            message: $payload['message'],
            subscriberIds: array_values($payload['subscriber_ids']),
            idempotencyKey: $payload['idempotency_key'],
        );
    }
}
