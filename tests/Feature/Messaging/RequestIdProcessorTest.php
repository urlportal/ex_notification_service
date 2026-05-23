<?php

namespace Tests\Feature\Messaging;

use App\Logging\RequestIdContext;
use App\Logging\RequestIdProcessor;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

class RequestIdProcessorTest extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();
        app(RequestIdContext::class)->forget();
    }

    public function test_processor_adds_request_id_to_log_record(): void
    {
        $context = app(RequestIdContext::class);
        $requestId = Uuid::uuid7()->toString();
        $context->set($requestId);

        $handler = new TestHandler;
        $logger = new Logger('test', [$handler]);
        $logger->pushProcessor(new RequestIdProcessor);

        $logger->info('test message');

        $records = $handler->getRecords();
        $this->assertCount(1, $records);
        $extra = $records[0]['extra'];
        $this->assertIsArray($extra);
        $this->assertSame($requestId, $extra['request_id']);
    }

    public function test_processor_does_not_add_request_id_after_forget(): void
    {
        $context = app(RequestIdContext::class);
        $context->set(Uuid::uuid7()->toString());
        $context->forget();

        $handler = new TestHandler;
        $logger = new Logger('test', [$handler]);
        $logger->pushProcessor(new RequestIdProcessor);

        $logger->info('test message');

        $records = $handler->getRecords();
        $this->assertCount(1, $records);
        $extra = $records[0]['extra'];
        $this->assertIsArray($extra);
        $this->assertArrayNotHasKey('request_id', $extra);
    }

    public function test_json_formatter_produces_valid_json_with_request_id(): void
    {
        $context = app(RequestIdContext::class);
        $requestId = Uuid::uuid7()->toString();
        $context->set($requestId);

        $formatter = new JsonFormatter;
        $handler = new TestHandler(Level::Debug, true);
        $handler->setFormatter($formatter);

        $logger = new Logger('test', [$handler]);
        $logger->pushProcessor(new RequestIdProcessor);

        $logger->info('structured log test');

        $records = $handler->getRecords();
        $this->assertCount(1, $records);

        $formatted = $formatter->format($records[0]);
        $decoded = json_decode($formatted, true);

        $this->assertIsArray($decoded, 'Log output must be valid JSON');
        $this->assertArrayHasKey('request_id', $decoded['extra'] ?? $decoded);
        $this->assertSame($requestId, $decoded['extra']['request_id'] ?? $decoded['request_id']);
    }
}
