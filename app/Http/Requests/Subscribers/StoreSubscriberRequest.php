<?php

namespace App\Http\Requests\Subscribers;

use App\Http\DTO\Subscribers\SubscriberDto;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreSubscriberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:32', 'regex:/^\+[1-9]\d{6,14}$/'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (empty($this->input('phone')) && empty($this->input('email'))) {
                $validator->errors()->add('phone', 'At least one of phone or email must be provided.');
            }
        });
    }

    public function toDto(): SubscriberDto
    {
        return new SubscriberDto(
            name: $this->input('name'),
            phone: $this->input('phone'),
            email: $this->input('email'),
        );
    }
}
