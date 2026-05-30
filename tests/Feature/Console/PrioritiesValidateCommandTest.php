<?php

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * notifications:priorities:validate сверяет DeliveryProfile с definitions.json.
 */
class PrioritiesValidateCommandTest extends TestCase
{
    private string $tmpPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpPath = tempnam(sys_get_temp_dir(), 'def').'.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->tmpPath)) {
            unlink($this->tmpPath);
        }
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function baselineDefinitions(): array
    {
        return [
            'queues' => [
                [
                    'name' => 'notifications.transactional',
                    'vhost' => '/',
                    'durable' => true,
                    'auto_delete' => false,
                    'arguments' => [
                        'x-queue-type' => 'quorum',
                        'x-dead-letter-exchange' => 'notifications.dlx',
                        'x-delivery-limit' => 5,
                    ],
                ],
                [
                    'name' => 'notifications.marketing',
                    'vhost' => '/',
                    'durable' => true,
                    'auto_delete' => false,
                    'arguments' => [
                        'x-queue-type' => 'quorum',
                        'x-dead-letter-exchange' => 'notifications.dlx',
                        'x-delivery-limit' => 5,
                    ],
                ],
            ],
            'bindings' => [
                [
                    'source' => 'notifications.exchange',
                    'vhost' => '/',
                    'destination' => 'notifications.transactional',
                    'destination_type' => 'queue',
                    'routing_key' => 'transactional',
                    'arguments' => [],
                ],
                [
                    'source' => 'notifications.exchange',
                    'vhost' => '/',
                    'destination' => 'notifications.marketing',
                    'destination_type' => 'queue',
                    'routing_key' => 'marketing',
                    'arguments' => [],
                ],
            ],
        ];
    }

    /** @param  array<string, mixed>  $definitions */
    private function writeDefinitions(array $definitions): void
    {
        file_put_contents($this->tmpPath, json_encode($definitions, JSON_THROW_ON_ERROR));
    }

    // Всё согласовано (exit 0)
    public function test_happy_path_returns_success(): void
    {
        $this->writeDefinitions($this->baselineDefinitions());

        $exitCode = Artisan::call('notifications:priorities:validate', [
            '--definitions-path' => $this->tmpPath,
        ]);
        $this->assertSame(0, $exitCode);
    }

    // Очередь отсутствует (exit 1), в выводе "queue not found"
    public function test_missing_queue_returns_failure(): void
    {
        $defs = $this->baselineDefinitions();
        // Удалить marketing-очередь.
        $defs['queues'] = [$defs['queues'][0]];
        $this->writeDefinitions($defs);

        $exitCode = Artisan::call('notifications:priorities:validate', [
            '--definitions-path' => $this->tmpPath,
        ]);
        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('queue not found', Artisan::output());
    }

    // x-queue-type=classic вместо quorum (exit 1)
    public function test_wrong_queue_type_returns_failure(): void
    {
        $defs = $this->baselineDefinitions();
        $defs['queues'][0]['arguments']['x-queue-type'] = 'classic';
        $this->writeDefinitions($defs);

        $exitCode = Artisan::call('notifications:priorities:validate', [
            '--definitions-path' => $this->tmpPath,
        ]);
        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('classic', Artisan::output());
    }

    // Отсутствует binding (exit 1), в выводе "binding not found"
    public function test_missing_binding_returns_failure(): void
    {
        $defs = $this->baselineDefinitions();
        // Удалить marketing-binding.
        $defs['bindings'] = [$defs['bindings'][0]];
        $this->writeDefinitions($defs);

        $exitCode = Artisan::call('notifications:priorities:validate', [
            '--definitions-path' => $this->tmpPath,
        ]);
        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('binding not found', Artisan::output());
    }

    // Несуществующий путь (exit 1), "definitions file not found".
    public function test_nonexistent_path_returns_failure(): void
    {
        $nonexistent = '/tmp/does-not-exist-'.uniqid().'.json';

        $exitCode = Artisan::call('notifications:priorities:validate', [
            '--definitions-path' => $nonexistent,
        ]);
        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('definitions file not found', Artisan::output());
    }
}
