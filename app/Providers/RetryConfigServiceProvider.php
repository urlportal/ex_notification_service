<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Валидирует согласованность retry-конфигурации на старте.
 *
 * Пример: count(notifications.retry.levels) === notifications.retry.max_attempts - 1.
 * Получим ошибку деплоя. Обнаруживается при инициализации.
 */
class RetryConfigServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $maxAttempts = (int) config('notifications.retry.max_attempts');
        $levels = (array) config('notifications.retry.levels');
        $expectedLevels = $maxAttempts - 1;

        if (count($levels) !== $expectedLevels) {
            $keys = array_map(
                static fn ($level) => (string) ($level['key'] ?? '?'),
                $levels,
            );

            throw new \InvalidArgumentException(sprintf(
                'Retry configuration mismatch: max_attempts=%d expects %d levels, found %d (keys: %s).',
                $maxAttempts,
                $expectedLevels,
                count($levels),
                implode(',', $keys),
            ));
        }
    }
}
