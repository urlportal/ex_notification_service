<?php

namespace App\Messaging\Inbox;

interface MsgInboxRepositoryInterface
{
    /**
     * Атомарная попытка зарегистрировать обработку сообщения данным consumer-ом.
     *
     * Вызывается внутри транзакции родительского кода: если транзакция
     * откатится, запись откатится вместе с ней.
     *
     * @param  string  $messageId  UUID сообщения.
     * @param  string  $consumerName  Имя consumer, kebab-case, до 80 символов (валидируется
     *                                в конструкторе `AbstractIdempotentMessageHandler`).
     * @return bool true  - первая обработка (INSERT успешен);
     *              false - уже обработано (PK violation, дубль).
     */
    public function markProcessed(string $messageId, string $consumerName): bool;
}
