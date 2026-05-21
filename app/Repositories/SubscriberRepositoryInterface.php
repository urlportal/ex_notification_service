<?php

namespace App\Repositories;

use App\Http\DTO\Subscribers\SubscriberDto;
use App\Models\Subscriber;
use Illuminate\Pagination\LengthAwarePaginator;

interface SubscriberRepositoryInterface
{
    public function findById(string $id): Subscriber;

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Subscriber>
     */
    public function paginate(array $filters = []): LengthAwarePaginator;

    public function create(SubscriberDto $dto): Subscriber;

    public function update(Subscriber $subscriber, SubscriberDto $dto): Subscriber;

    public function delete(Subscriber $subscriber): void;

    public function existsByPhone(?string $phone, ?string $excludeId = null): bool;

    public function existsByEmail(?string $email, ?string $excludeId = null): bool;
}
