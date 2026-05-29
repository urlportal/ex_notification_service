<?php

namespace App\Console\Commands;

use App\Messaging\AmqpConnectionFactory;
use App\Messaging\Handlers\BulkExpandEventHandler;
use Illuminate\Console\Command;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;

class BulkExpandConsumerCommand extends Command
{
    protected $signature = 'notifications:consume-bulk-expand
        {--prefetch=1 : prefetch_count для basic_qos}
        {--max-messages=0 : 0 = бесконечно; >0 для детерминированных тестов}';

    protected $description = 'Читает bulk-expand-event из notifications.bulk-expand и разворачивает в N уведомлений.';

    public function __construct(
        private readonly AmqpConnectionFactory $amqpFactory,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $prefetch = (int) $this->option('prefetch');
        $maxMessages = (int) $this->option('max-messages');
        $processed = 0;
        $shouldStop = false;

        // Асинхронная доставка сигналов - обработчик выполняется между опкодами PHP
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, function () use (&$shouldStop): void {
            $shouldStop = true;
        });
        pcntl_signal(SIGINT, function () use (&$shouldStop): void {
            $shouldStop = true;
        });

        $handler = app(BulkExpandEventHandler::class);
        $queue = (string) config('rabbitmq.bulk_expand.queue');

        $channel = $this->amqpFactory->connection()->channel();
        $channel->basic_qos(0, $prefetch, false);

        $tag = $channel->basic_consume(
            $queue, '', false, false, false, false,
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
