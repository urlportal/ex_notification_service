<?php

namespace App\Messaging;

use App\Logging\RequestIdContext;
use App\Messaging\Inbox\MsgInboxRepositoryInterface;
use Illuminate\Database\DatabaseManager;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

abstract class AbstractIdempotentMessageHandler
{
    private const string CONSUMER_NAME_REGEX = '/^[a-z][a-z0-9-]{0,79}$/';

    public function __construct(
        protected readonly MsgInboxRepositoryInterface $inbox,
        protected readonly DatabaseManager $db,
        protected readonly LoggerInterface $logger,
        protected readonly RequestIdContext $requestIdContext,
    ) {
        $consumerName = $this->consumerName();
        if (preg_match(self::CONSUMER_NAME_REGEX, $consumerName) !== 1) {
            throw new \InvalidArgumentException(
                sprintf('Invalid consumer_name "%s": expected kebab-case, max 80 chars.', $consumerName),
            );
        }
    }

    abstract protected function consumerName(): string;

    /**
     * Бизнес-логика наследника. Вызывается ровно один раз для каждой пары
     * (message_id, consumer_name). Транзакция БД уже открыта вызывающим
     * кодом; наследник не должен делать ack/nack — это сделает handle().
     *
     * @param  array<string, mixed>  $headers  Декодированные application_headers сообщения.
     */
    abstract protected function handleInternal(
        AMQPMessage $message,
        string $messageId,
        array $headers,
    ): void;

    final public function handle(AMQPMessage $message): void
    {
        $consumerName = $this->consumerName();
        $logContext = ['consumer_name' => $consumerName];

        $rawMessageId = $message->has('message_id') ? (string) $message->get('message_id') : null;
        $headers = $this->extractHeaders($message);

        $requestId = isset($headers['request_id']) && is_string($headers['request_id'])
            ? $headers['request_id']
            : null;

        if ($requestId === null) {
            $requestId = Uuid::uuid7()->toString();
            $this->logger->warning(
                'message without request_id',
                $logContext + ['request_id' => $requestId],
            );
        }
        $logContext['request_id'] = $requestId;
        $this->requestIdContext->set($requestId);

        try {
            if ($rawMessageId === null) {
                $this->logger->warning(
                    'message without message_id, idempotency disabled, ack',
                    $logContext,
                );
                $message->ack();

                return;
            }

            $logContext['message_id'] = $rawMessageId;

            if (! Uuid::isValid($rawMessageId)) {
                $this->logger->warning(
                    'message with invalid message_id, idempotency disabled, ack',
                    $logContext,
                );
                $message->ack();

                return;
            }

            $this->db->transaction(function () use ($message, $rawMessageId, $headers, $consumerName, $logContext): void {
                $isFirst = $this->inbox->markProcessed($rawMessageId, $consumerName);
                if (! $isFirst) {
                    $this->logger->info('duplicate message, idempotent skip', $logContext);

                    return;
                }
                $this->handleInternal($message, $rawMessageId, $headers);
            });

            $message->ack();
        } catch (\Throwable $e) {
            $this->logger->error(
                'unhandled exception in handler',
                $logContext + [
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ],
            );
            $message->nack(requeue: false);
        } finally {
            $this->requestIdContext->forget();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function extractHeaders(AMQPMessage $message): array
    {
        $props = $message->get_properties();
        $headers = $props['application_headers'] ?? null;

        return $headers instanceof AMQPTable ? $headers->getNativeData() : [];
    }
}
