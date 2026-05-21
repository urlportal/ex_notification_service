<?php

namespace App\Http\Requests\Subscribers;

use App\Http\DTO\Subscribers\SubscriberDto;
use App\Models\Subscriber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateSubscriberRequest extends FormRequest
{
    private bool $subscriberResolved = false;

    private ?Subscriber $currentSubscriber = null;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/^\+[1-9]\d{6,14}$/'],
            'email' => ['sometimes', 'nullable', 'string', 'email', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $subscriber = $this->resolveCurrentSubscriber();

            if ($subscriber === null) {
                return;
            }

            $mergedPhone = $this->has('phone') ? $this->input('phone') : $subscriber->phone;
            $mergedEmail = $this->has('email') ? $this->input('email') : $subscriber->email;

            if ($mergedPhone === null && $mergedEmail === null) {
                $validator->errors()->add('phone', 'At least one of phone or email must be provided.');
            }
        });
    }

    public function toDto(): SubscriberDto
    {
        $subscriber = $this->resolveCurrentSubscriber();

        $mergedPhone = $this->has('phone') ? $this->input('phone') : $subscriber?->phone;
        $mergedEmail = $this->has('email') ? $this->input('email') : $subscriber?->email;
        $mergedName = $this->has('name') ? $this->input('name') : $subscriber?->name;

        return new SubscriberDto(
            name: $mergedName,
            phone: $mergedPhone,
            email: $mergedEmail,
        );
    }

    private function resolveCurrentSubscriber(): ?Subscriber
    {
        if (! $this->subscriberResolved) {
            $this->subscriberResolved = true;
            $id = $this->route('id');
            if ($id !== null) {
                $found = Subscriber::query()->find($id);
                $this->currentSubscriber = $found instanceof Subscriber ? $found : null;
            }
        }

        return $this->currentSubscriber;
    }
}
