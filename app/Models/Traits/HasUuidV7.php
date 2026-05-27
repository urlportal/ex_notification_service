<?php

namespace App\Models\Traits;

use Illuminate\Database\Eloquent\Model;
use Ramsey\Uuid\Uuid;

/**
 * @mixin Model
 */
trait HasUuidV7
{
    protected static function bootHasUuidV7(): void
    {
        static::creating(static function (self $model): void {
            if (empty($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = Uuid::uuid7()->toString();
            }
        });
    }
}
