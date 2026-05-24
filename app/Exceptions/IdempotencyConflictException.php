<?php

namespace App\Exceptions;

class IdempotencyConflictException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Request is being processed');
    }
}
