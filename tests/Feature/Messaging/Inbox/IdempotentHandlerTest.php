<?php

namespace Tests\Feature\Messaging\Inbox;

use App\Logging\RequestIdContext;
use App\Messaging\AbstractIdempotentMessageHandler;
use App\Messaging\Inbox\MsgInboxRepositoryInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Tests\Support\Messaging\StubAuditIdempotentHandler;
use Tests\Support\Messaging\StubIdempotentHandler;
use Tests\Support\Messaging\ThrowingStubIdempotentHandler;
use Tests\TestCase;

class IdempotentHandlerTest extends TestCase
{
    use RefreshDatabase;

    private AMQPChannel $channelMock;

    private LoggerInterface $logSpy;

    private RequestIdContext $requestIdContext;

    protected function setUp(): void
    {
        parent::setUp();

        $this->channelMock = $this->freshChannelMock();

        $logSpy = Mockery::spy(LoggerInterface::class);
        /** @var LoggerInterface $logSpy */
        $this->logSpy = $logSpy;
        $this->app->instance(LoggerInterface::class, $this->logSpy);

        $this->requestIdContext = $this->app->make(RequestIdContext::class);
    }

    private function freshChannelMock(): AMQPChannel
    {
        $mock = Mockery::mock(AMQPChannel::class);
        $mock->shouldReceive('basic_ack')->andReturnNull()->byDefault();
        $mock->shouldReceive('basic_nack')->andReturnNull()->byDefault();

        /** @var AMQPChannel $mock */
        return $mock;
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $headers
     * @param  array<string, mixed>  $payload
     */
    private function makeMessage(?string $messageId, array $headers = [], array $payload = [], ?AMQPChannel $channel = null): AMQPMessage
    {
        $props = [];
        if ($messageId !== null) {
            $props['message_id'] = $messageId;
        }
        if ($headers !== []) {
            $props['application_headers'] = new AMQPTable($headers);
        }

        $message = new AMQPMessage(json_encode($payload, JSON_THROW_ON_ERROR), $props);
        $message->setChannel($channel ?? $this->channelMock);
        $message->setDeliveryInfo(deliveryTag: 1, redelivered: false, exchange: '', routingKey: '');

        return $message;
    }

    private function freshStubHandler(): StubIdempotentHandler
    {
        return $this->app->make(StubIdempotentHandler::class);
    }

    public function test_first_delivery_runs_handle_internal_and_acks(): void
    {
        $messageId = Uuid::uuid7()->toString();
        $handler = $this->freshStubHandler();
        $message = $this->makeMessage(
            messageId: $messageId,
            headers: ['request_id' => 'R-1'],
            payload: ['notification_id' => 'N-1'],
        );

        $handler->handle($message);

        $this->assertCount(1, $handler->calls);
        $this->assertSame($messageId, $handler->calls[0]['message_id']);
        $this->assertSame('R-1', $handler->calls[0]['request_id']);
        $this->assertSame('N-1', $handler->calls[0]['payload']['notification_id']);

        $this->assertSame(1, DB::table('msg_inbox')
            ->where('message_id', $messageId)
            ->where('consumer_name', 'stub-consumer')
            ->count());

        $this->channelMock->shouldHaveReceived('basic_ack')->once();
        $this->channelMock->shouldNotHaveReceived('basic_nack');

        // После handle() RequestIdContext должен быть очищен.
        $this->assertNull($this->requestIdContext->get());
    }

    public function test_missing_request_id_header_generates_uuid_v7_and_logs_warning(): void
    {
        $messageId = Uuid::uuid7()->toString();
        $handler = $this->freshStubHandler();
        $message = $this->makeMessage(
            messageId: $messageId,
            headers: [],
            payload: ['notification_id' => 'N-2'],
        );

        $handler->handle($message);

        $this->assertCount(1, $handler->calls);
        $generatedRequestId = $handler->calls[0]['request_id'];
        $this->assertIsString($generatedRequestId);
        $this->assertTrue(Uuid::isValid($generatedRequestId));

        $this->logSpy->shouldHaveReceived('warning')->withArgs(
            fn (string $msg, array $context) => $msg === 'message without request_id'
                && $context['consumer_name'] === 'stub-consumer'
                && $context['request_id'] === $generatedRequestId,
        )->once();

        $this->channelMock->shouldHaveReceived('basic_ack')->once();
    }

    public function test_duplicate_delivery_is_idempotent_skipped_and_acked(): void
    {
        $messageId = Uuid::uuid7()->toString();
        $handler = $this->freshStubHandler();

        $first = $this->makeMessage(
            messageId: $messageId,
            headers: ['request_id' => 'R-dup'],
            payload: ['notification_id' => 'N-dup'],
        );
        $second = $this->makeMessage(
            messageId: $messageId,
            headers: ['request_id' => 'R-dup'],
            payload: ['notification_id' => 'N-dup'],
        );

        $handler->handle($first);
        $handler->handle($second);

        $this->assertCount(1, $handler->calls, 'handleInternal должен быть вызван ровно один раз');
        $this->assertSame(1, DB::table('msg_inbox')
            ->where('message_id', $messageId)
            ->where('consumer_name', 'stub-consumer')
            ->count());

        $this->logSpy->shouldHaveReceived('info')->withArgs(
            fn (string $msg, array $context) => $msg === 'duplicate message, idempotent skip'
                && $context['consumer_name'] === 'stub-consumer'
                && $context['message_id'] === $messageId
                && $context['request_id'] === 'R-dup',
        )->once();

        $this->channelMock->shouldHaveReceived('basic_ack')->twice();
        $this->channelMock->shouldNotHaveReceived('basic_nack');
    }

    public function test_different_consumer_names_process_same_message_independently(): void
    {
        $messageId = Uuid::uuid7()->toString();

        // У каждого handler свой mock-канал — иначе проверка вызовов basic_ack
        // не позволит различить, кто из них что вызвал.
        $mainChannel = $this->freshChannelMock();
        $auditChannel = $this->freshChannelMock();

        $mainHandler = $this->app->make(StubIdempotentHandler::class);
        $auditHandler = $this->app->make(StubAuditIdempotentHandler::class);

        $mainMessage = $this->makeMessage(
            messageId: $messageId,
            headers: ['request_id' => 'R-fanout'],
            payload: ['notification_id' => 'N-fanout'],
            channel: $mainChannel,
        );
        $auditMessage = $this->makeMessage(
            messageId: $messageId,
            headers: ['request_id' => 'R-fanout'],
            payload: ['notification_id' => 'N-fanout'],
            channel: $auditChannel,
        );

        $mainHandler->handle($mainMessage);
        $auditHandler->handle($auditMessage);

        $this->assertCount(1, $mainHandler->calls);
        $this->assertCount(1, $auditHandler->calls);

        $rows = DB::table('msg_inbox')->where('message_id', $messageId)->orderBy('consumer_name')->get();
        $this->assertCount(2, $rows);
        $this->assertSame(['stub-audit-consumer', 'stub-consumer'], $rows->pluck('consumer_name')->all());

        $mainChannel->shouldHaveReceived('basic_ack')->once();
        $auditChannel->shouldHaveReceived('basic_ack')->once();
    }

    public function test_exception_in_handle_internal_rolls_back_inbox_and_nacks(): void
    {
        $messageId = Uuid::uuid7()->toString();
        $boom = new \RuntimeException('boom');

        $handler = $this->app->make(ThrowingStubIdempotentHandler::class, ['toThrow' => $boom]);

        $message = $this->makeMessage(
            messageId: $messageId,
            headers: ['request_id' => 'R-err'],
            payload: ['notification_id' => 'N-err'],
        );

        // handle() сам ловит исключения (не оборачиваем в try/catch).
        $handler->handle($message);

        $this->assertSame(0, DB::table('msg_inbox')
            ->where('message_id', $messageId)
            ->count(), 'строка в msg_inbox должна откатиться вместе с транзакцией');

        $this->logSpy->shouldHaveReceived('error')->withArgs(
            fn (string $msg, array $context) => $msg === 'unhandled exception in handler'
                && $context['exception'] === \RuntimeException::class
                && $context['message'] === 'boom'
                && $context['message_id'] === $messageId
                && $context['consumer_name'] === 'stub-consumer'
                && $context['request_id'] === 'R-err',
        )->once();

        $this->channelMock->shouldHaveReceived('basic_nack')->withArgs(
            fn ($_deliveryTag, $_multiple, $requeue) => $requeue === false,
        )->once();
        $this->channelMock->shouldNotHaveReceived('basic_ack');
    }

    public function test_missing_message_id_warns_and_acks_without_touching_inbox(): void
    {
        $handler = $this->freshStubHandler();
        $message = $this->makeMessage(
            messageId: null,
            headers: ['request_id' => 'R-no-msgid'],
            payload: ['notification_id' => 'N-no-msgid'],
        );

        $handler->handle($message);

        $this->assertCount(0, $handler->calls);
        $this->assertSame(0, DB::table('msg_inbox')->count());

        $this->logSpy->shouldHaveReceived('warning')->withArgs(
            fn (string $msg, array $context) => $msg === 'message without message_id, idempotency disabled, ack'
                && $context['consumer_name'] === 'stub-consumer'
                && $context['request_id'] === 'R-no-msgid',
        )->once();

        $this->channelMock->shouldHaveReceived('basic_ack')->once();
        $this->channelMock->shouldNotHaveReceived('basic_nack');
    }

    public function test_invalid_uuid_message_id_warns_and_acks_without_touching_inbox(): void
    {
        $handler = $this->freshStubHandler();
        $message = $this->makeMessage(
            messageId: 'not-a-uuid',
            headers: ['request_id' => 'R-bad-uuid'],
            payload: ['notification_id' => 'N-bad-uuid'],
        );

        $handler->handle($message);

        $this->assertCount(0, $handler->calls);
        $this->assertSame(0, DB::table('msg_inbox')->count());

        $this->logSpy->shouldHaveReceived('warning')->withArgs(
            fn (string $msg, array $context) => $msg === 'message with invalid message_id, idempotency disabled, ack'
                && $context['consumer_name'] === 'stub-consumer'
                && $context['message_id'] === 'not-a-uuid'
                && $context['request_id'] === 'R-bad-uuid',
        )->once();

        $this->channelMock->shouldHaveReceived('basic_ack')->once();
        $this->channelMock->shouldNotHaveReceived('basic_nack');
    }

    public function test_invalid_consumer_name_in_constructor_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new class($this->app->make(MsgInboxRepositoryInterface::class), $this->app->make(DatabaseManager::class), $this->logSpy, $this->requestIdContext) extends AbstractIdempotentMessageHandler
        {
            protected function consumerName(): string
            {
                return 'INVALID_NAME';
            }

            protected function handleInternal(AMQPMessage $message, string $messageId, array $headers): void {}
        };
    }

    public function test_non_amqptable_headers_are_treated_as_empty(): void
    {
        $messageId = Uuid::uuid7()->toString();
        $handler = $this->freshStubHandler();

        // Конструируем сообщение в обход makeMessage(): application_headers
        // должны быть не AMQPTable, а обычным массивом. Это нарушение контракта,
        // которое handler должен переварить как пустые headers.
        $message = new AMQPMessage('[]', [
            'message_id' => $messageId,
            'application_headers' => ['raw' => 'array'],
        ]);
        $message->setChannel($this->channelMock);
        $message->setDeliveryInfo(deliveryTag: 1, redelivered: false, exchange: '', routingKey: '');

        $handler->handle($message);

        // headers разобрались как пустой массив, request_id не найден.
        $this->logSpy->shouldHaveReceived('warning')->withArgs(
            fn (string $msg, array $context) => $msg === 'message without request_id'
                && $context['consumer_name'] === 'stub-consumer'
                && is_string($context['request_id'] ?? null)
                && Uuid::isValid($context['request_id']),
        )->once();

        // handleInternal всё равно вызван (message_id валиден).
        $this->assertCount(1, $handler->calls);
        $this->channelMock->shouldHaveReceived('basic_ack')->once();
        $this->channelMock->shouldNotHaveReceived('basic_nack');
    }
}
