<?php

namespace Tests\Support\Messaging;

use App\Logging\RequestIdContext;
use App\Messaging\AbstractIdempotentMessageHandler;
use App\Messaging\Inbox\MsgInboxRepositoryInterface;
use Illuminate\Database\DatabaseManager;
use PhpAmqpLib\Message\AMQPMessage;
use Psr\Log\LoggerInterface;

final class ThrowingStubIdempotentHandler extends AbstractIdempotentMessageHandler
{
    public function __construct(
        MsgInboxRepositoryInterface $inbox,
        DatabaseManager $db,
        LoggerInterface $logger,
        RequestIdContext $requestIdContext,
        private readonly \Throwable $toThrow,
    ) {
        parent::__construct($inbox, $db, $logger, $requestIdContext);
    }

    protected function consumerName(): string
    {
        return 'stub-consumer';
    }

    protected function handleInternal(AMQPMessage $message, string $messageId, array $headers): void
    {
        throw $this->toThrow;
    }
}
