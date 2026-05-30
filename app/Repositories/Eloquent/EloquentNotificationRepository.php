<?php

namespace App\Repositories\Eloquent;

use App\Enums\NotificationStatus;
use App\Exceptions\NotFoundException;
use App\Models\Notification;
use App\Repositories\NotificationRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
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
            'id', 'subscriber_id', 'bulk_request_id', 'channel', 'priority',
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
}
