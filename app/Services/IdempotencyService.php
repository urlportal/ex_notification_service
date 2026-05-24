<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis;

class IdempotencyService
{
    private const PREFIX = 'idempotency:bulk:';

    public function __construct(private readonly int $defaultTtlSeconds) {}

    /**
     * Атомарно резервирует ключ идемпотентности через SET NX EX.
     * Возвращает true, если ключ был установлен (запрос новый).
     * Возвращает false, если ключ уже существовал (повтор или параллельный запрос).
     */
    public function acquire(string $key, int $ttlSeconds): bool
    {
        $result = Redis::connection()->client()->set(
            $this->lockKey($key),
            '1',
            ['nx', 'ex' => $ttlSeconds],
        );

        return $result !== false && $result !== null;
    }

    /**
     * Сохраняет HTTP-ответ в Redis для последующей выдачи повторным запросам.
     *
     * @param  array<string, mixed>  $response
     */
    public function storeResponse(string $key, array $response, int $ttlSeconds): void
    {
        Redis::setex(
            $this->responseKey($key),
            $ttlSeconds,
            json_encode($response, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * Возвращает закэшированный ответ или null, если он ещё не сохранён.
     *
     * @return array<string, mixed>|null
     */
    public function getResponse(string $key): ?array
    {
        $data = Redis::get($this->responseKey($key));

        if ($data === null || $data === false) {
            return null;
        }

        return json_decode($data, true, 512, JSON_THROW_ON_ERROR);
    }

    public function getDefaultTtl(): int
    {
        return $this->defaultTtlSeconds;
    }

    private function lockKey(string $key): string
    {
        return self::PREFIX.$key;
    }

    private function responseKey(string $key): string
    {
        return self::PREFIX.$key.':response';
    }
}
