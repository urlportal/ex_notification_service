<?php

namespace App\Messaging\Outbox\Eloquent;

use App\Messaging\Outbox\MsgOutbox;
use App\Messaging\Outbox\MsgOutboxEntry;
use App\Messaging\Outbox\MsgOutboxRepositoryInterface;
use Illuminate\Support\Facades\DB;

class EloquentMsgOutboxRepository implements MsgOutboxRepositoryInterface
{
    public function enqueue(MsgOutboxEntry $entry): void
    {
        MsgOutbox::create([
            'id' => $entry->id,
            'exchange' => $entry->exchange,
            'routing_key' => $entry->routingKey,
            'payload' => $entry->payload,
            'headers' => $entry->headers,
        ]);
    }

    public function enqueueMany(MsgOutboxEntry ...$entries): void
    {
        if (empty($entries)) {
            return;
        }

        $rows = array_map(
            static fn (MsgOutboxEntry $entry): array => [
                'id' => $entry->id,
                'exchange' => $entry->exchange,
                'routing_key' => $entry->routingKey,
                'payload' => json_encode($entry->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'headers' => json_encode($entry->headers, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'status' => 'pending',
                'attempts' => 0,
            ],
            $entries,
        );

        DB::table('msg_outbox')->insert($rows);
    }

    public function pullPendingBatch(int $limit): array
    {
        $rows = DB::select(
            <<<'SQL'
            SELECT id, exchange, routing_key, payload, headers, status, attempts, last_error, created_at, published_at
            FROM msg_outbox
            WHERE status = 'pending'
            ORDER BY created_at
            LIMIT ?
            FOR UPDATE SKIP LOCKED
            SQL,
            [$limit],
        );

        return array_map(
            static function (object $row): MsgOutbox {
                /** @var MsgOutbox $model */
                $model = (new MsgOutbox)->newFromBuilder((array) $row);

                return $model;
            },
            $rows,
        );
    }

    public function markPublishedMany(array $ids): void
    {
        if (empty($ids)) {
            return;
        }

        MsgOutbox::query()
            ->whereIn('id', $ids)
            ->update([
                'status' => 'published',
                'published_at' => now(),
            ]);
    }

    public function markFailed(string $id, string $error): void
    {
        MsgOutbox::query()
            ->where('id', $id)
            ->update([
                'status' => 'failed',
                'last_error' => $error,
                'attempts' => DB::raw('attempts + 1'),
            ]);
    }

    public function incrementAttempts(string $id): void
    {
        MsgOutbox::query()
            ->where('id', $id)
            ->increment('attempts');
    }
}
