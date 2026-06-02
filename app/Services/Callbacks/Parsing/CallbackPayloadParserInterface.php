<?php

namespace App\Services\Callbacks\Parsing;

use App\Dto\ProviderCallbackDto;
use App\Enums\NotificationChannel;
use App\Exceptions\Callbacks\InvalidCallbackPayloadException;
use Illuminate\Http\Request;

interface CallbackPayloadParserInterface
{
    /**
     * @throws InvalidCallbackPayloadException
     */
    public function parse(Request $request, NotificationChannel $channel): ?ProviderCallbackDto;
}
