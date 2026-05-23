<?php

namespace Tests\Feature\Messaging;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AssignRequestIdMiddlewareTest extends TestCase
{
    public function test_valid_request_id_is_echoed_back(): void
    {
        $validId = '01960000-0000-7000-8000-000000000001';

        $response = $this->get('/api/health/live', ['X-Request-ID' => $validId]);

        $response->assertHeader('X-Request-ID', $validId);
    }

    public function test_missing_request_id_generates_uuid(): void
    {
        $response = $this->get('/api/health/live');

        $id = $response->headers->get('X-Request-ID');
        $this->assertNotNull($id);
        $this->assertSame(36, strlen($id));
    }

    public function test_invalid_request_id_too_long_generates_new_uuid(): void
    {
        $invalidId = str_repeat('a', 65);

        $response = $this->get('/api/health/live', ['X-Request-ID' => $invalidId]);

        $id = $response->headers->get('X-Request-ID');
        $this->assertNotNull($id);
        $this->assertNotEquals($invalidId, $id);
        $this->assertSame(36, strlen($id));
    }

    public function test_invalid_request_id_bad_chars_generates_new_uuid(): void
    {
        $response = $this->get('/api/health/live', ['X-Request-ID' => 'bad id with spaces!']);

        $id = $response->headers->get('X-Request-ID');
        $this->assertNotNull($id);
        $this->assertNotEquals('bad id with spaces!', $id);
        $this->assertSame(36, strlen($id));
    }

    public function test_health_endpoint_returns_request_id_without_db_queries(): void
    {
        DB::enableQueryLog();

        $healthId = '01960000-0000-7000-8000-000000000002';
        $response = $this->get('/api/health/live', ['X-Request-ID' => $healthId]);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $response->assertHeader('X-Request-ID', $healthId);
        $this->assertEmpty($queries);
    }
}
