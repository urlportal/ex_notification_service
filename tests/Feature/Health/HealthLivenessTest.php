<?php

namespace Tests\Feature\Health;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HealthLivenessTest extends TestCase
{
    public function test_returns_200_with_correct_content_type(): void
    {
        $response = $this->get('/api/health/live');

        $response->assertStatus(200);
        $this->assertStringContainsString(
            'application/health+json',
            $response->headers->get('Content-Type') ?? ''
        );
    }

    public function test_returns_cache_control_header(): void
    {
        $response = $this->get('/api/health/live');

        $cacheControl = $response->headers->get('Cache-Control') ?? '';
        $this->assertStringContainsString('no-cache', $cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('must-revalidate', $cacheControl);
    }

    public function test_returns_correct_json_structure(): void
    {
        $response = $this->get('/api/health/live');

        $response->assertJson([
            'status' => 'pass',
        ]);

        $data = $response->json();
        $this->assertNotEmpty($data['version']);
        $this->assertNotEmpty($data['serviceId']);
    }

    public function test_does_not_execute_sql_queries(): void
    {
        DB::enableQueryLog();

        $this->get('/api/health/live');

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertEmpty($queries);
    }
}
