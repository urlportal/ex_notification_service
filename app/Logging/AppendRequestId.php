<?php

namespace App\Logging;

use Illuminate\Log\Logger;

class AppendRequestId
{
    public function __invoke(Logger $logger): void
    {
        /** @var \Monolog\Logger $monolog */
        $monolog = $logger->getLogger();
        $monolog->pushProcessor(new RequestIdProcessor);
    }
}
