<?php

namespace Tests\Feature\Messaging;

use App\Logging\AppendRequestId;
use App\Logging\RequestIdContext;
use App\Logging\RequestIdProcessor;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
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
        $context->set('rid-1');

        $handler = new TestHandler();
        $logger = new Logger('test', [$handler]);
        $logger->pushProcessor(new RequestIdProcessor());

        $logger->info('test message');

        $records = $handler->getRecords();
        $this->assertCount(1, $records);
        $this->assertSame('rid-1', $records[0]['extra']['request_id']);
    }

    public function test_processor_does_not_add_request_id_after_forget(): void
    {
        $context = app(RequestIdContext::class);
        $context->set('rid-1');
        $context->forget();

        $handler = new TestHandler();
        $logger = new Logger('test', [$handler]);
        $logger->pushProcessor(new RequestIdProcessor());

        $logger->info('test message');

        $records = $handler->getRecords();
        $this->assertCount(1, $records);
        $this->assertArrayNotHasKey('request_id', $records[0]['extra']);
    }

    public function test_json_formatter_produces_valid_json_with_request_id(): void
    {
        $context = app(RequestIdContext::class);
        $context->set('rid-json-test');

        $formatter = new JsonFormatter();
        $handler = new TestHandler(Level::Debug, true);
        $handler->setFormatter($formatter);

        $logger = new Logger('test', [$handler]);
        $logger->pushProcessor(new RequestIdProcessor());

        $logger->info('structured log test');

        $records = $handler->getRecords();
        $this->assertCount(1, $records);

        $formatted = $formatter->format($records[0]);
        $decoded = json_decode($formatted, true);

        $this->assertIsArray($decoded, 'Log output must be valid JSON');
        $this->assertArrayHasKey('request_id', $decoded['extra'] ?? $decoded);
        $this->assertSame('rid-json-test', $decoded['extra']['request_id'] ?? $decoded['request_id']);
    }
}
