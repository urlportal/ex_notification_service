<?php

namespace App\Console\Commands;

use App\Messaging\DeliveryProfile;
use Illuminate\Console\Command;

/**
 * Сверяет DeliveryProfile с топологией из definitions.json.
 *
 * Для каждого профиля доставки проверяет:
 * - очередь с соответствующим name присутствует в queues[];
 * - arguments содержат x-queue-type="quorum", x-dead-letter-exchange=
 *   "notifications.dlx", любое значение x-delivery-limit;
 * - в bindings[] есть запись source="notifications.exchange",
 *   destination=<queueName>, routing_key=<priority name>.
 *
 * Любое несоответствие (exit 1) с таблицей-отчётом.
 * Файл не найден / не парсится (exit 1).
 */
class PrioritiesValidateCommand extends Command
{
    protected $signature = 'notifications:priorities:validate
        {--definitions-path=deploy/dev/rabbitmq/definitions.json}';

    protected $description = 'Сверяет DeliveryProfile с deploy/.../rabbitmq/definitions.json.';

    public function handle(DeliveryProfile $profile): int
    {
        $path = (string) $this->option('definitions-path');
        if (! is_file($path)) {
            $this->error("definitions file not found: {$path}");

            return self::FAILURE;
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            $this->error("definitions file not readable: {$path}");

            return self::FAILURE;
        }

        $definitions = json_decode($raw, true);
        if (! is_array($definitions)) {
            $this->error("invalid JSON in {$path}");

            return self::FAILURE;
        }

        $queues = is_array($definitions['queues'] ?? null) ? $definitions['queues'] : [];
        $bindings = is_array($definitions['bindings'] ?? null) ? $definitions['bindings'] : [];

        $queuesByName = [];
        foreach ($queues as $q) {
            if (is_array($q) && isset($q['name']) && is_string($q['name'])) {
                $queuesByName[$q['name']] = $q;
            }
        }

        $rows = [];
        $hasError = false;

        foreach ($profile->names() as $name) {
            $queueName = $profile->queueName($name);

            $queueCheck = 'ok';
            $q = $queuesByName[$queueName] ?? null;
            if ($q === null) {
                $queueCheck = 'queue not found';
            } else {
                $args = is_array($q['arguments'] ?? null) ? $q['arguments'] : [];
                $issues = [];
                if (($args['x-queue-type'] ?? null) !== 'quorum') {
                    $value = var_export($args['x-queue-type'] ?? null, true);
                    $issues[] = "x-queue-type={$value}";
                }
                if (($args['x-dead-letter-exchange'] ?? null) !== 'notifications.dlx') {
                    $value = var_export($args['x-dead-letter-exchange'] ?? null, true);
                    $issues[] = "x-dead-letter-exchange={$value}";
                }
                if (! array_key_exists('x-delivery-limit', $args)) {
                    $issues[] = 'missing x-delivery-limit';
                }
                if ($issues !== []) {
                    $queueCheck = implode('; ', $issues);
                }
            }

            $bindingCheck = 'ok';
            $foundBinding = false;
            foreach ($bindings as $b) {
                if (! is_array($b)) {
                    continue;
                }
                if (($b['source'] ?? null) === 'notifications.exchange'
                    && ($b['destination'] ?? null) === $queueName
                    && ($b['routing_key'] ?? null) === $name) {
                    $foundBinding = true;
                    break;
                }
            }
            if (! $foundBinding) {
                $bindingCheck = 'binding not found';
            }

            $rowOk = ($queueCheck === 'ok' && $bindingCheck === 'ok');
            if (! $rowOk) {
                $hasError = true;
            }
            $rows[] = [$name, $queueName, $rowOk ? 'ok' : 'fail', $queueCheck, $bindingCheck];
        }

        $this->table(['priority', 'queue', 'status', 'queue check', 'binding check'], $rows);

        return $hasError ? self::FAILURE : self::SUCCESS;
    }
}
