<?php

namespace App\Logging;

use Illuminate\Log\Logger;

class AppendRequestId
{
    public function __invoke(Logger $logger): void
    {
        $logger->getLogger()->pushProcessor(new RequestIdProcessor());
    }
}
