<?php

namespace App\Providers;

use App\Services\RescueSweepThreshold;
use Illuminate\Support\ServiceProvider;

/**
 * Вычисляет порог обнаружения потерявшихся уведомлений и валидирует конфиг при старте.
 *
 * Если RESCUE_SWEEP_THRESHOLD_MINUTES не задан, порог вычисляется автоматически:
 * max(ttl_ms всех retry-уровней) / 60000 + 15 мин.
 * Если задан явно и оказывается меньше или равен max(ttl_ms / 60000), получаем исключение при старте.
 * Интервал запуска по расписанию должен делить час без остатка, иначе cron-выражение
 * с шагом N минут сбрасывается в начале каждого часа и даёт неравномерные запуски.
 */
class RescueSweepConfigServiceProvider extends ServiceProvider
{
    /**
     * Делители 60: только с ними запуски каждые N минут идут через равные промежутки.
     */
    public const ALLOWED_SCHEDULE_MINUTES = [1, 2, 3, 4, 5, 6, 10, 12, 15, 20, 30, 60];

    public function boot(): void
    {
        $this->resolveThreshold(new RescueSweepThreshold);
        $this->validateScheduleMinutes();
    }

    private function resolveThreshold(RescueSweepThreshold $threshold): void
    {
        $configured = config('notifications.rescue_sweep.threshold_minutes');

        if ($configured === null) {
            config(['notifications.rescue_sweep.threshold_minutes' => $threshold->defaultMinutes()]);

            return;
        }

        if (! $threshold->isValid((int) $configured)) {
            throw new \InvalidArgumentException(sprintf(
                'RESCUE_SWEEP_THRESHOLD_MINUTES (%d) must exceed the longest retry queue TTL (%d min). '
                .'Increase the value or leave it unset for auto-calculation.',
                $configured,
                $threshold->maxRetryTtlMinutes(),
            ));
        }
    }

    private function validateScheduleMinutes(): void
    {
        $scheduleMinutes = (int) config('notifications.rescue_sweep.schedule_minutes');

        if (! in_array($scheduleMinutes, self::ALLOWED_SCHEDULE_MINUTES, true)) {
            throw new \InvalidArgumentException(sprintf(
                'RESCUE_SWEEP_SCHEDULE_MINUTES (%d) must be one of: %s.',
                $scheduleMinutes,
                implode(', ', self::ALLOWED_SCHEDULE_MINUTES),
            ));
        }
    }
}
