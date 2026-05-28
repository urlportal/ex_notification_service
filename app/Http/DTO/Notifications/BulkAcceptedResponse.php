<?php

namespace App\Http\DTO\Notifications;

readonly class BulkAcceptedResponse
{
    /**
     * @param  list<string>  $invalidSubscriberIds  Несуществующие subscriber_ids в порядке запроса
     */
    public function __construct(
        public string $bulkRequestId,
        public int $accepted,
        public array $invalidSubscriberIds,
    ) {}

    /**
     * @return array{bulk_request_id: string, accepted: int, invalid_subscriber_ids: list<string>}
     */
    public function toArray(): array
    {
        return [
            'bulk_request_id' => $this->bulkRequestId,
            'accepted' => $this->accepted,
            'invalid_subscriber_ids' => $this->invalidSubscriberIds,
        ];
    }
}
