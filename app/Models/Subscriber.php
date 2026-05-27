<?php

namespace App\Models;

use App\Models\Traits\HasUuidV7;
use Illuminate\Database\Eloquent\Model;

class Subscriber extends Model
{
    use HasUuidV7;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['id', 'name', 'phone', 'email'];
}
