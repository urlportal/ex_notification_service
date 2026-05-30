<?php

namespace App\Http\Requests\Notifications;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Messaging\DeliveryProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexSubscriberNotificationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $profiles = $this->container->get(DeliveryProfile::class);

        return [
            'status' => ['sometimes', 'string', Rule::enum(NotificationStatus::class)],
            'channel' => ['sometimes', 'string', Rule::enum(NotificationChannel::class)],
            'priority' => ['sometimes', 'string', Rule::in($profiles->names())],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
