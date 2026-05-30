<?php

namespace App\Console\Commands;

use App\Messaging\AmqpConnectionFactory;
use App\Messaging\DeliveryProfile;
use Illuminate\Console\Command;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;

/**
 * Pre-deploy валидация: для каждой записи из DeliveryProfile делает
 * queue_declare(passive=true) против реального брокера. Открывает свой
 * channel на каждую очередь (RabbitMQ закрывает channel после 404).
 *
 * Статусы строки таблицы: ok / missing / error: <reason>. Любая не-ok
 * строка - exit 1. Сбой connection - exit 1 до построения таблицы.
 */
class PrioritiesCheckBrokerCommand extends Command
{
    protected $signature = 'notifications:priorities:check-broker';

    protected $description = 'Проверяет, что для каждого профиля доставки есть очередь в брокере.';

    public function __construct(
        private readonly DeliveryProfile $profile,
        private readonly AmqpConnectionFactory $amqpFactory,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $connection = $this->amqpFactory->connection();
        } catch (\Throwable $e) {
            $this->error('cannot connect to broker: '.$e->getMessage());

            return self::FAILURE;
        }

        $rows = [];
        $hasError = false;

        foreach ($this->profile->names() as $name) {
            $queueName = $this->profile->queueName($name);
            $status = 'ok';

            try {
                $channel = $connection->channel();
                try {
                    $channel->queue_declare($queueName, true);
                } finally {
                    if ($channel->is_open()) {
                        $channel->close();
                    }
                }
            } catch (AMQPProtocolChannelException $e) {
                $status = $e->amqp_reply_code === 404
                    ? 'missing'
                    : "error: {$e->amqp_reply_code}/{$e->amqp_reply_text}";
                $hasError = true;
            } catch (\Throwable $e) {
                $status = 'error: '.$e->getMessage();
                $hasError = true;
            }

            $rows[] = [$name, $queueName, $status];
        }

        $this->amqpFactory->close();
        $this->table(['priority', 'queue', 'status'], $rows);

        return $hasError ? self::FAILURE : self::SUCCESS;
    }
}
