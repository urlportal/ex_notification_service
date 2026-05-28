<?php

namespace App\Messaging\Inbox\Eloquent;

use App\Messaging\Inbox\MsgInboxRepositoryInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class EloquentMsgInboxRepository implements MsgInboxRepositoryInterface
{
    public function markProcessed(string $messageId, string $consumerName): bool
    {
        // INSERT оборачиваем в DB::transaction, чтобы при существующей внешней
        // транзакции (handler::handle) Laravel использовал SAVEPOINT. Без этого
        // unique-violation отменит внешнюю транзакцию PostgreSQL (SQLSTATE
        // 25P02) и любые последующие запросы в ней падают.
        try {
            DB::transaction(static function () use ($messageId, $consumerName): void {
                DB::insert(
                    'INSERT INTO msg_inbox (message_id, consumer_name) VALUES (?, ?)',
                    [$messageId, $consumerName],
                );
            });

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }
}
