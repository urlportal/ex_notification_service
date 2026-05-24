<?php

namespace App\Exceptions;

class SubscribersNotFoundException extends \RuntimeException
{
    /** @param array<int, string> $missingIds */
    public function __construct(private readonly array $missingIds)
    {
        parent::__construct('Some subscriber_ids do not exist.');
    }

    /** @return array<int, string> */
    public function getMissingIds(): array
    {
        return $this->missingIds;
    }
}
