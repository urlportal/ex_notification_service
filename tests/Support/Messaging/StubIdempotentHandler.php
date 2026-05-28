<?php

namespace Tests\Support\Messaging;

use App\Messaging\AbstractIdempotentMessageHandler;
use PhpAmqpLib\Message\AMQPMessage;

final class StubIdempotentHandler extends AbstractIdempotentMessageHandler
{
    /** @var list<array{message_id: string, payload: array<string, mixed>, request_id: ?string}> */
    public array $calls = [];

    protected function consumerName(): string
    {
        return 'stub-consumer';
    }

    protected function handleInternal(AMQPMessage $message, string $messageId, array $headers): void
    {
        $body = $message->getBody();
        $decoded = $body === '' ? [] : json_decode($body, true, flags: JSON_THROW_ON_ERROR);

        $this->calls[] = [
            'message_id' => $messageId,
            'payload' => is_array($decoded) ? $decoded : [],
            'request_id' => $this->requestIdContext->get(),
        ];
    }
}
