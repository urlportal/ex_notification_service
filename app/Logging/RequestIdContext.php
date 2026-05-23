<?php

namespace App\Logging;

class RequestIdContext
{
    private ?string $id = null;

    public function set(string $id): void
    {
        $this->id = $id;
    }

    public function get(): ?string
    {
        return $this->id;
    }

    public function forget(): void
    {
        $this->id = null;
    }
}
