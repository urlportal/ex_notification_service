<?php

namespace App\Services;

final class RescueSweepResult
{
    public function __construct(
        public int $found = 0,
        public int $requeued = 0,
        public int $dropped = 0,
        public int $failed = 0,
        public int $skipped = 0,
    ) {}
}
