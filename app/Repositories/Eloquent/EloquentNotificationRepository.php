<?php

namespace App\Repositories\Eloquent;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Exceptions\NotFoundException;
use App\Models\Notification;
use App\Repositories\NotificationRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
            ->when($filters['delivery_profile'] ?? null, fn ($q, $v) => $q->where('delivery_profile', $v))
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    public function findByIdempotencyKey(string $key): ?Notification
    {
        return Notification::where('idempotency_key', $key)->first();
    }

    public function findByProviderMessageId(string $providerMessageId, NotificationChannel $channel): ?Notification
    {
        return Notification::where('provider_message_id', $providerMessageId)
            ->where('channel', $channel)
            ->first();
    }

    public function markDelivered(string $id, \DateTimeImmutable $deliveredAt): void
    {
        Notification::query()->whereKey($id)->update([
            'status' => NotificationStatus::Delivered->value,
            'delivered_at' => $deliveredAt,
            'updated_at' => now(),
        ]);
    }

    public function markDroppedFromCallback(string $id, \DateTimeImmutable $droppedAt, string $error): void
    {
        Notification::query()->whereKey($id)->update([
            'status' => NotificationStatus::Dropped->value,
            'dropped_at' => $droppedAt,
            'last_error' => $error,
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, string>
     */
    public function createBulk(array $rows): array
    {
        DB::table('notifications')->insert($rows);

        return array_column($rows, 'id');
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, string>
     */
    public function upsertBulkAndReturnIds(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        // Статический белый список колонок. Значения передаются через bindings.
        $columns = [
            'id', 'subscriber_id', 'bulk_request_id', 'channel', 'delivery_profile',
            'status', 'message', 'idempotency_key', 'request_id',
            'queued_at', 'created_at', 'updated_at',
        ];

        $placeholders = [];
        $bindings = [];

        foreach ($rows as $row) {
            $placeholders[] = '('.implode(',', array_fill(0, count($columns), '?')).')';
            foreach ($columns as $col) {
                $bindings[] = $row[$col];
            }
        }

        $columnList = implode(',', $columns);
        $valueList = implode(',', $placeholders);

        $sql = "INSERT INTO notifications ({$columnList}) VALUES {$valueList}"
            .' ON CONFLICT (bulk_request_id, subscriber_id) DO UPDATE SET id = notifications.id'
            .' RETURNING id, subscriber_id';

        $result = DB::select($sql, $bindings);

        $map = [];
        foreach ($result as $row) {
            $map[(string) $row->subscriber_id] = (string) $row->id;
        }

        return $map;
    }

    public function lockForUpdateById(string $id): ?Notification
    {
        // Опирается на транзакцию, открытую вызывающим кодом
        // (AbstractIdempotentMessageHandler оборачивает handleInternal).
        return Notification::query()->whereKey($id)->lockForUpdate()->first();
    }

    public function updateOnSent(
        string $id,
        string $providerMessageId,
        \DateTimeImmutable $sentAt,
        int $attempts,
    ): void {
        Notification::query()->whereKey($id)->update([
            'status' => NotificationStatus::Sent->value,
            'sent_at' => $sentAt,
            'provider_message_id' => $providerMessageId,
            'attempts' => $attempts,
            // Сброс возможной ошибки от прежних retry-попыток.
            'last_error' => null,
            'updated_at' => now(),
        ]);
    }

    public function updateOnDropped(
        string $id,
        \DateTimeImmutable $droppedAt,
        string $lastError,
        int $attempts,
    ): void {
        Notification::query()->whereKey($id)->update([
            'status' => NotificationStatus::Dropped->value,
            'dropped_at' => $droppedAt,
            'last_error' => $lastError,
            'attempts' => $attempts,
            'updated_at' => now(),
        ]);
    }

    public function updateAttemptsAndError(string $id, int $attempts, string $lastError): void
    {
        Notification::query()->whereKey($id)->update([
            'attempts' => $attempts,
            'last_error' => $lastError,
            'updated_at' => now(),
        ]);
    }

    /**
     * @return Collection<int, Notification>
     */
    public function findStuckBatchForUpdate(\DateTimeImmutable $stuckBefore, int $batchSize, array $excludeIds = []): Collection
    {
        return Notification::query()
            ->where('status', NotificationStatus::Queued->value)
            ->where('attempts', '>', 0)
            ->where('updated_at', '<', $stuckBefore->format('Y-m-d H:i:s'))
            ->when($excludeIds !== [], fn ($query) => $query->whereNotIn('id', $excludeIds))
            ->orderBy('updated_at')
            ->lock('FOR UPDATE SKIP LOCKED')
            ->limit($batchSize)
            ->get();
    }

    public function touchForRescue(string $id): void
    {
        Notification::query()->whereKey($id)->update([
            'updated_at' => now(),
        ]);
    }

    public function countStuck(\DateTimeImmutable $stuckBefore): int
    {
        return Notification::query()
            ->where('status', NotificationStatus::Queued->value)
            ->where('attempts', '>', 0)
            ->where('updated_at', '<', $stuckBefore->format('Y-m-d H:i:s'))
            ->count();
    }
}
