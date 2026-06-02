<?php

namespace App\Repositories;

use App\Enums\NotificationChannel;
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

    public function findByProviderMessageId(string $providerMessageId, NotificationChannel $channel): ?Notification;

    /**
     * UPDATE при получении callback доставки: status=delivered, delivered_at.
     * Счётчик attempts не изменяется.
     */
    public function markDelivered(string $id, \DateTimeImmutable $deliveredAt): void;

    /**
     * UPDATE при получении callback отклонения: status=dropped, dropped_at, last_error.
     * Счётчик attempts не изменяется.
     */
    public function markDroppedFromCallback(string $id, \DateTimeImmutable $droppedAt, string $error): void;

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

    /**
     * UPDATE между попытками: status НЕ меняется, обновляются только attempts
     * и last_error. Используется при принятии решения о retry
     */
    public function updateAttemptsAndError(
        string $id,
        int $attempts,
        string $lastError,
    ): void;
}
