<?php

namespace App\Exceptions\Callbacks;

use RuntimeException;

class InvalidCallbackSignatureException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("Invalid callback signature: {$reason}");
    }
}
