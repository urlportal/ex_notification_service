<?php

namespace App\Messaging\Outbox;

use App\Models\Traits\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $exchange
 * @property string $routing_key
 * @property array<string, mixed> $payload
 * @property array<string, mixed> $headers
 * @property OutboxStatus $status
 * @property int $attempts
 * @property string|null $last_error
 * @property Carbon|null $published_at
 */
class MsgOutbox extends Model
{
    use HasUuidV7;

    protected $table = 'msg_outbox';

    public $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'id',
        'exchange',
        'routing_key',
        'payload',
        'headers',
        'status',
        'attempts',
        'last_error',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'headers' => 'array',
            'status' => OutboxStatus::class,
            'published_at' => 'datetime',
        ];
    }
}
