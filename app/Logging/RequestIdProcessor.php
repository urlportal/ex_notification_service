<?php

namespace App\Logging;

use Monolog\LogRecord;

class RequestIdProcessor
{
    public function __invoke(LogRecord $record): LogRecord
    {
        $id = app(RequestIdContext::class)->get();

        if ($id === null) {
            return $record;
        }

        return $record->with(extra: array_merge($record->extra, ['request_id' => $id]));
    }
}
