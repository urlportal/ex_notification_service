<?php

namespace App\Http\Requests\Notifications;

use App\Enums\NotificationChannel;
use App\Http\DTO\Notifications\BulkSendNotificationDto;
use App\Messaging\DeliveryProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkSendNotificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $profiles = $this->container->get(DeliveryProfile::class);

        return [
            'idempotency_key' => ['required', 'uuid'],
            'channel' => ['required', 'string', Rule::in(array_column(NotificationChannel::cases(), 'value'))],
            'priority' => ['required', 'string', Rule::in($profiles->names())],
            'message' => ['required', 'string', 'min:1', 'max:1000'],
            'subscriber_ids' => ['required', 'array', 'min:1', 'max:'.config('notifications.bulk.max_subscribers')],
            'subscriber_ids.*' => ['required', 'uuid', 'distinct'],
        ];
    }

    public function toDto(): BulkSendNotificationDto
    {
        return new BulkSendNotificationDto(
            channel: NotificationChannel::from($this->string('channel')->value()),
            priority: $this->string('priority')->value(),
            message: $this->string('message')->value(),
            subscriberIds: $this->collect('subscriber_ids')->values()->all(),
            idempotencyKey: $this->string('idempotency_key')->value(),
        );
    }
}
