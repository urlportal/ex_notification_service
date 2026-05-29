<?php

namespace App\Repositories;

use App\Models\Notification;
use Illuminate\Pagination\LengthAwarePaginator;

interface NotificationRepositoryInterface
{
    public function findById(string $id): Notification;

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Notification>
     */
    public function paginateBySubscriber(string $subscriberId, array $filters, int $perPage): LengthAwarePaginator;

    public function findByIdempotencyKey(string $key): ?Notification;

    public function findByProviderMessageId(string $id): ?Notification;

    /**
     * Создаёт N уведомлений одним SQL INSERT.
     * ID должны быть предварительно заполнены в каждой строке.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, string>
     */
    public function createBulk(array $rows): array;

    /**
     * Батч-upsert уведомлений с возвратом актуальных ID.
     * При конфликте по (bulk_request_id, subscriber_id) возвращает существующий ID.
     *
     * @param  array<int, array<string, mixed>>  $rows  строки со всеми заполненными колонками
     * @return array<string, string> [subscriber_id => actual_notification_id]
     */
    public function upsertBulkAndReturnIds(array $rows): array;
}
