<?php

namespace App\Messaging;

use App\Exceptions\Messaging\InvalidDeliveryProfileException;
use App\Exceptions\Messaging\UnknownPriorityException;

/**
 * Профиль доставки: иммутабельный набор настроек (очередь, воркеры, prefetch)
 * Строится при boot из config('notifications.delivery_profiles').
 * Источник истины для валидации delivery_profile (HTTP-приём, bulk-expand-handler) и
 * резолва имени AMQP-очереди в sender-воркере.
 */
final class DeliveryProfile
{
    private const NAME_REGEX = '/^[a-z][a-z0-9_]*$/';

    /** @var array<string, DeliveryProfileConfig> */
    private array $byName;

    /** @var list<string> */
    private array $orderedNames;

    /**
     * @param  array<mixed, mixed>  $config  массив из config('notifications.delivery_profiles')
     */
    public function __construct(array $config)
    {
        if ($config === []) {
            throw new InvalidDeliveryProfileException('priorities list is empty');
        }

        // Стратегия collect-all: пройти весь массив, собрать все нарушения и бросить
        // одно исключение с полным списком. Boot-time проверка, не runtime hot-path.
        // Полный список экономит цикл "исправить - перезапустить - найти следующую".
        $violations = [];
        $entries = [];
        $orderedNames = [];

        foreach ($config as $name => $row) {
            if (! is_string($name) || preg_match(self::NAME_REGEX, $name) !== 1) {
                $violations[] = sprintf('invalid priority name: %s', var_export($name, true));

                continue;
            }

            if (! is_array($row)) {
                $violations[] = sprintf('%s: entry must be array', $name);

                continue;
            }

            $rowViolations = [];

            $queue = $row['queue'] ?? null;
            if (! is_string($queue) || $queue === '') {
                $rowViolations[] = 'queue must be a non-empty string';
            }

            $workers = $row['workers'] ?? null;
            if (! is_int($workers) || $workers < 1) {
                $rowViolations[] = 'workers must be int >= 1';
            }

            $prefetch = $row['prefetch'] ?? null;
            if (! is_int($prefetch) || $prefetch < 1) {
                $rowViolations[] = 'prefetch must be int >= 1';
            }

            if ($rowViolations !== []) {
                foreach ($rowViolations as $v) {
                    $violations[] = sprintf('%s: %s', $name, $v);
                }

                continue;
            }

            /** @var string $queue */
            /** @var int $workers */
            /** @var int $prefetch */
            $entries[$name] = new DeliveryProfileConfig(
                name: $name,
                queue: $queue,
                workers: $workers,
                prefetch: $prefetch,
            );
            $orderedNames[] = $name;
        }

        if ($violations !== []) {
            throw new InvalidDeliveryProfileException(
                'invalid priorities config: '.implode('; ', $violations)
            );
        }

        $this->byName = $entries;
        $this->orderedNames = $orderedNames;
    }

    /**
     * @return list<string> Имена профилей доставки в порядке записи в конфиге.
     */
    public function names(): array
    {
        return $this->orderedNames;
    }

    public function has(string $name): bool
    {
        return isset($this->byName[$name]);
    }

    /**
     * Имя AMQP-очереди для профиля доставки.
     *
     * @throws UnknownPriorityException
     */
    public function queueName(string $name): string
    {
        return $this->get($name)->queue;
    }

    /**
     * @throws UnknownPriorityException
     */
    public function get(string $name): DeliveryProfileConfig
    {
        if (! isset($this->byName[$name])) {
            throw new UnknownPriorityException("unknown profile: {$name}");
        }

        return $this->byName[$name];
    }

    /**
     * @return array<string, DeliveryProfileConfig>
     */
    public function all(): array
    {
        return $this->byName;
    }
}
