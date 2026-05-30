<?php

namespace Tests\Unit\Messaging;

use App\Exceptions\Messaging\InvalidDeliveryProfileException;
use App\Exceptions\Messaging\UnknownPriorityException;
use App\Messaging\DeliveryProfile;
use App\Messaging\DeliveryProfileConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DeliveryProfileTest extends TestCase
{
    public function test_constructs_with_valid_config(): void
    {
        $profile = new DeliveryProfile([
            'transactional' => ['queue' => 'q.tx', 'workers' => 2, 'prefetch' => 10],
            'marketing' => ['queue' => 'q.mk', 'workers' => 4, 'prefetch' => 5],
        ]);

        self::assertSame(['transactional', 'marketing'], $profile->names());
        self::assertTrue($profile->has('transactional'));
        self::assertTrue($profile->has('marketing'));
        self::assertFalse($profile->has('phantom'));
        self::assertSame('q.tx', $profile->queueName('transactional'));
        self::assertSame('q.mk', $profile->queueName('marketing'));

        $tx = $profile->get('transactional');
        self::assertInstanceOf(DeliveryProfileConfig::class, $tx);
        self::assertSame('transactional', $tx->name);
        self::assertSame('q.tx', $tx->queue);
        self::assertSame(2, $tx->workers);
        self::assertSame(10, $tx->prefetch);

        $all = $profile->all();
        self::assertCount(2, $all);
        self::assertSame(['transactional', 'marketing'], array_keys($all));
    }

    public function test_throws_on_empty_config(): void
    {
        $this->expectException(InvalidDeliveryProfileException::class);
        $this->expectExceptionMessage('priorities list is empty');

        new DeliveryProfile([]);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidNamesProvider(): iterable
    {
        yield 'PascalCase' => ['Transactional'];
        yield 'kebab-case' => ['tx-1'];
        yield 'with space' => ['tx 1'];
        yield 'empty string' => [''];
        yield 'starts with digit' => ['1tx'];
        yield 'uppercase' => ['TX'];
    }

    #[DataProvider('invalidNamesProvider')]
    public function test_throws_on_invalid_name(string $name): void
    {
        $this->expectException(InvalidDeliveryProfileException::class);

        new DeliveryProfile([$name => ['queue' => 'q', 'workers' => 1, 'prefetch' => 1]]);
    }

    public function test_throws_on_empty_queue(): void
    {
        $this->expectException(InvalidDeliveryProfileException::class);
        $this->expectExceptionMessage('queue must be a non-empty string');

        new DeliveryProfile(['tx' => ['queue' => '', 'workers' => 1, 'prefetch' => 1]]);
    }

    public function test_throws_on_workers_zero(): void
    {
        $this->expectException(InvalidDeliveryProfileException::class);
        $this->expectExceptionMessage('workers must be int >= 1');

        new DeliveryProfile(['tx' => ['queue' => 'q', 'workers' => 0, 'prefetch' => 1]]);
    }

    public function test_throws_on_prefetch_zero(): void
    {
        $this->expectException(InvalidDeliveryProfileException::class);
        $this->expectExceptionMessage('prefetch must be int >= 1');

        new DeliveryProfile(['tx' => ['queue' => 'q', 'workers' => 1, 'prefetch' => 0]]);
    }

    public function test_throws_on_missing_workers_field(): void
    {
        $this->expectException(InvalidDeliveryProfileException::class);
        $this->expectExceptionMessage('workers must be int >= 1');

        new DeliveryProfile(['tx' => ['queue' => 'q', 'prefetch' => 1]]);
    }

    public function test_throws_on_missing_queue_field(): void
    {
        $this->expectException(InvalidDeliveryProfileException::class);
        $this->expectExceptionMessage('queue must be a non-empty string');

        new DeliveryProfile(['tx' => ['workers' => 1, 'prefetch' => 1]]);
    }

    public function test_collects_all_violations_in_single_exception(): void
    {
        // Collect-all: одно исключение перечисляет нарушения обоих приоритетов.
        try {
            new DeliveryProfile([
                'Bad' => ['queue' => 'q', 'workers' => 1, 'prefetch' => 1],
                'tx' => ['queue' => '', 'workers' => 0, 'prefetch' => 0],
            ]);
            self::fail('expected InvalidDeliveryProfileException');
        } catch (InvalidDeliveryProfileException $e) {
            $msg = $e->getMessage();
            self::assertStringContainsString("'Bad'", $msg);
            self::assertStringContainsString('tx: queue must be a non-empty string', $msg);
            self::assertStringContainsString('tx: workers must be int >= 1', $msg);
            self::assertStringContainsString('tx: prefetch must be int >= 1', $msg);
        }
    }

    public function test_throws_unknown_priority_on_queue_name(): void
    {
        $profile = new DeliveryProfile(['tx' => ['queue' => 'q', 'workers' => 1, 'prefetch' => 1]]);

        $this->expectException(UnknownPriorityException::class);
        $this->expectExceptionMessage('unknown priority: phantom');

        $profile->queueName('phantom');
    }

    public function test_throws_unknown_priority_on_get(): void
    {
        $profile = new DeliveryProfile(['tx' => ['queue' => 'q', 'workers' => 1, 'prefetch' => 1]]);

        $this->expectException(UnknownPriorityException::class);

        $profile->get('phantom');
    }
}
