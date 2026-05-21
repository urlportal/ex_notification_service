<?php

namespace Tests\Feature\Documentation;

use Tests\TestCase;

class OpenApiSmokeTest extends TestCase
{
    public function test_openapi_json_is_valid_and_contains_all_operations(): void
    {
        $response = $this->get('/api/openapi.json');

        $response->assertStatus(200);

        $spec = $response->json();
        $this->assertNotNull($spec, 'openapi.json must be valid JSON');
        $this->assertArrayHasKey('paths', $spec);

        $expectedPaths = [
            '/api/health/live',
            '/api/v1/subscribers',
            '/api/v1/subscribers/{id}',
        ];

        foreach ($expectedPaths as $path) {
            $this->assertArrayHasKey($path, $spec['paths'], "Path {$path} must exist in openapi.json");
        }

        $operations = [];
        foreach ($spec['paths'] as $methods) {
            foreach (array_keys($methods) as $method) {
                $operations[] = $method;
            }
        }

        $this->assertGreaterThanOrEqual(6, count($operations), 'openapi.json must contain at least 6 operations');
    }

    public function test_swagger_ui_returns_200(): void
    {
        $response = $this->get('/api/documentation');

        $response->assertStatus(200);
    }
}
