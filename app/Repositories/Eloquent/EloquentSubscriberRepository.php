<?php

namespace App\Repositories\Eloquent;

use App\Exceptions\NotFoundException;
use App\Http\DTO\Subscribers\SubscriberDto;
use App\Models\Subscriber;
use App\Repositories\SubscriberRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class EloquentSubscriberRepository implements SubscriberRepositoryInterface
{
    public function findById(string $id): Subscriber
    {
        $subscriber = Subscriber::find($id);

        if ($subscriber === null) {
            throw new NotFoundException('Resource not found');
        }

        return $subscriber;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Subscriber>
     */
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        return Subscriber::query()
            ->when($filters['phone'] ?? null, fn ($q, $v) => $q->where('phone', $v))
            ->when($filters['email'] ?? null, fn ($q, $v) => $q->where('email', $v))
            ->orderByDesc('created_at')
            ->paginate(15);
    }

    public function create(SubscriberDto $dto): Subscriber
    {
        return Subscriber::create([
            'name' => $dto->name,
            'phone' => $dto->phone,
            'email' => $dto->email,
        ]);
    }

    public function update(Subscriber $subscriber, SubscriberDto $dto): Subscriber
    {
        $subscriber->update([
            'name' => $dto->name,
            'phone' => $dto->phone,
            'email' => $dto->email,
        ]);

        $subscriber->refresh();

        return $subscriber;
    }

    public function delete(Subscriber $subscriber): void
    {
        $subscriber->delete();
    }

    public function existsByPhone(?string $phone, ?string $excludeId = null): bool
    {
        if ($phone === null) {
            return false;
        }

        return Subscriber::query()
            ->where('phone', $phone)
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->exists();
    }

    public function existsByEmail(?string $email, ?string $excludeId = null): bool
    {
        if ($email === null) {
            return false;
        }

        return Subscriber::query()
            ->where('email', $email)
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->exists();
    }

    /**
     * @param  array<int, string>  $ids
     * @return array<int, string>
     */
    public function findExistingIds(array $ids): array
    {
        return Subscriber::query()
            ->whereIn('id', $ids)
            ->pluck('id')
            ->all();
    }
}
