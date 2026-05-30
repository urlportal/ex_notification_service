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

    /**
     * SELECT...FOR UPDATE по PK уведомления внутри текущей DB-транзакции.
     * Вызывается из sender-handler под транзакцией базового
     * AbstractIdempotentMessageHandler. Собственной транзакции не открывает.
     */
    public function lockForUpdateById(string $id): ?Notification;

    /**
     * UPDATE при успешной отправке: status=sent, sent_at, provider_message_id,
     * attempts, last_error=NULL.
     */
    public function updateOnSent(
        string $id,
        string $providerMessageId,
        \DateTimeImmutable $sentAt,
        int $attempts,
    ): void;

    /**
     * UPDATE при отказе провайдера: status=dropped, dropped_at, last_error,
     * attempts. provider_message_id и sent_at не трогаются.
     */
    public function updateOnDropped(
        string $id,
        \DateTimeImmutable $droppedAt,
        string $lastError,
        int $attempts,
    ): void;
}
