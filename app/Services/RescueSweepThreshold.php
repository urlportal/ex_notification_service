<?php

namespace App\Services;

/**
 * Правило порога rescue sweep: порог должен превышать TTL самой долгой retry-очереди.
 * Иначе уведомления, которые штатно ждут в retry-очереди, будут ошибочно сочтены потерянными
 * и поставлены в очередь повторно.
 */
final class RescueSweepThreshold
{
    /**
     * Запас поверх максимального TTL для порога по умолчанию.
     */
    private const DEFAULT_BUFFER_MINUTES = 15;

    public function maxRetryTtlMinutes(): int
    {
        $ttlValuesMs = array_column((array) config('notifications.retry.levels'), 'ttl_ms');

        if ($ttlValuesMs === []) {
            throw new \InvalidArgumentException('notifications.retry.levels must not be empty.');
        }

        return (int) ceil(max($ttlValuesMs) / 60000);
    }

    public function defaultMinutes(): int
    {
        return $this->maxRetryTtlMinutes() + self::DEFAULT_BUFFER_MINUTES;
    }

    public function isValid(int $thresholdMinutes): bool
    {
        return $thresholdMinutes > $this->maxRetryTtlMinutes();
    }
}
