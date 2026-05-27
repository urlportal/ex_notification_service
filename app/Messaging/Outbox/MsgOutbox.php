<?php

namespace App\Messaging\Outbox;

use App\Models\Traits\HasUuidV7;
use Illuminate\Database\Eloquent\Model;

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
