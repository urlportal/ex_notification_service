<?php

namespace App\Http\Resources;

use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Notification $resource
 */
class NotificationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'subscriber_id' => $this->resource->subscriber_id,
            'bulk_request_id' => $this->resource->bulk_request_id,
            'channel' => $this->resource->channel->value,
            'priority' => $this->resource->priority->value,
            'status' => $this->resource->status->value,
            'message' => $this->resource->message,
            'attempts' => $this->resource->attempts,
            'last_error' => $this->resource->last_error,
            'idempotency_key' => $this->resource->idempotency_key,
            'request_id' => $this->resource->request_id,
            'provider_message_id' => $this->resource->provider_message_id,
            'queued_at' => $this->resource->queued_at,
            'sent_at' => $this->resource->sent_at,
            'delivered_at' => $this->resource->delivered_at,
            'dropped_at' => $this->resource->dropped_at,
            'created_at' => $this->resource->created_at,
            'updated_at' => $this->resource->updated_at,
        ];
    }
}
