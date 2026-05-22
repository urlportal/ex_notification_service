<?php

namespace App\Repositories\Eloquent;

use App\Exceptions\NotFoundException;
use App\Models\Notification;
use App\Repositories\NotificationRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class EloquentNotificationRepository implements NotificationRepositoryInterface
{
    public function findById(string $id): Notification
    {
        $notification = Notification::find($id);

        if ($notification === null) {
            throw new NotFoundException('Resource not found');
        }

        return $notification;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Notification>
     */
    public function paginateBySubscriber(string $subscriberId, array $filters, int $perPage): LengthAwarePaginator
    {
        return Notification::query()
            ->where('subscriber_id', $subscriberId)
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['channel'] ?? null, fn ($q, $v) => $q->where('channel', $v))
            ->when($filters['priority'] ?? null, fn ($q, $v) => $q->where('priority', $v))
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    public function findByIdempotencyKey(string $key): ?Notification
    {
        return Notification::where('idempotency_key', $key)->first();
    }

    public function findByProviderMessageId(string $id): ?Notification
    {
        return Notification::where('provider_message_id', $id)->first();
    }
}
