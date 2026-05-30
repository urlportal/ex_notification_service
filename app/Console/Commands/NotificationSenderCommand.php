<?php

namespace App\Console\Commands;

use App\Messaging\AmqpConnectionFactory;
use App\Messaging\Handlers\SendNotificationHandler;
use Illuminate\Console\Command;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Sender-consumer. Читает send-команды из приоритетной очереди и через
 * SendNotificationHandler вызывает канальный gateway. Одна команда обслуживает
 * две (transactional / marketing). Выбор через --queue.
 */
class NotificationSenderCommand extends Command
{
    protected $signature = 'notifications:send
        {--queue= : transactional или marketing}
        {--prefetch=10 : prefetch_count для basic_qos}
        {--max-messages=0 : 0 = бесконечно; >0 для детерминированных тестов}';

    protected $description = 'Читает send-команды из приоритетной очереди и отправляет уведомления через gateway.';

    public function __construct(
        private readonly AmqpConnectionFactory $amqpFactory,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $queueOption = (string) $this->option('queue');
        $queueName = config("rabbitmq.queues.{$queueOption}");

        if (! is_string($queueName) || $queueName === '') {
            $this->error('invalid queue value');

            return self::FAILURE;
        }

        $consumerName = "sender-{$queueOption}";
        $prefetch = (int) $this->option('prefetch');
        $maxMessages = (int) $this->option('max-messages');
        $processed = 0;
        $shouldStop = false;

        // Асинхронная доставка сигналов - обработчик срабатывает между опкодами PHP.
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, function () use (&$shouldStop): void {
            $shouldStop = true;
        });
        pcntl_signal(SIGINT, function () use (&$shouldStop): void {
            $shouldStop = true;
        });

        $handler = app()->makeWith(SendNotificationHandler::class, [
            'consumerName' => $consumerName,
        ]);

        $channel = $this->amqpFactory->connection()->channel();
        $channel->basic_qos(0, $prefetch, false);

        $tag = $channel->basic_consume(
            $queueName, '', false, false, false, false,
            function (AMQPMessage $message) use ($handler, &$processed): void {
                $handler->handle($message);
                $processed++;
            },
        );

        while (! $shouldStop && $channel->is_consuming() && ($maxMessages === 0 || $processed < $maxMessages)) {
            try {
                $channel->wait(null, false, 1);
            } catch (AMQPTimeoutException) {
                // В очереди нет сообщений втечение 1 сек, поэтому продолжаем цикл, проверяя флаг остановки.
            }
        }

        $channel->basic_cancel($tag);
        $channel->close();
        $this->amqpFactory->close();

        return self::SUCCESS;
    }
}
