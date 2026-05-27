<?php

namespace App\Messaging\Outbox;

interface MsgOutboxRepositoryInterface
{
    /**
     * Сохранит одно сообщение в outbox со статусом pending.
     */
    public function enqueue(MsgOutboxEntry $entry): void;

    /**
     * Батч-вставка нескольких сообщений в outbox.
     * Все строки получают статус pending.
     */
    public function enqueueMany(MsgOutboxEntry ...$entries): void;

    /**
     * Извлекает батч pending-строк с блокировкой FOR UPDATE SKIP LOCKED.
     *
     * Метод должен вызываться внутри транзакции вызывающей стороны.
     * Блокировка удерживается до коммита транзакции, что предотвращает
     * конкурентную обработку одних и тех же строк несколькими relay-процессами.
     *
     * @param  int  $limit  Максимальное число строк в батче.
     * @return MsgOutbox[]
     */
    public function pullPendingBatch(int $limit): array;

    /**
     * Пометит список строк как published. Операция идемпотентна.
     *
     * @param  string[]  $ids  Идентификаторы строк.
     */
    public function markPublishedMany(array $ids): void;

    /**
     * Пометит строку как failed и сохранит текст ошибки.
     * Инкрементирует attempts = attempts + 1.
     */
    public function markFailed(string $id, string $error): void;

    /**
     * Инкрементирует счётчик попыток для строки.
     */
    public function incrementAttempts(string $id): void;
}
