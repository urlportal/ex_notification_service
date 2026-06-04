<?php

namespace App\Console\Commands;

use App\Messaging\AmqpConnectionFactory;
use App\Messaging\DeliveryProfile;
use App\Messaging\Handlers\SendNotificationHandler;
use Illuminate\Console\Command;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use Psr\Log\LoggerInterface;

/**
 * Sender-consumer. Читает send-команды из приоритетной очереди и через
 * SendNotificationHandler вызывает канальный gateway. Одна команда обслуживает
 * все типы рассылок (transactional, marketing, ...). Выбор через --queue
 * (имя типа из config/notifications.php).
 */
class NotificationSenderCommand extends Command
{
    protected $signature = 'notifications:send
        {--queue= : профиль из DeliveryProfile}
        {--prefetch=10 : prefetch_count для basic_qos}
        {--max-messages=0 : 0 = бесконечно; >0 для детерминированных тестов}';

    protected $description = 'Читает send-команды из приоритетной очереди и отправляет уведомления через gateway.';

    public function __construct(
        private readonly AmqpConnectionFactory $amqpFactory,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    public function handle(DeliveryProfile $profile): int
    {
        $queueOption = (string) $this->option('queue');

        // --queue принимает имя типа рассылки; имя AMQP-очереди резолвится через профиль доставки.
        if (! $profile->has($queueOption)) {
            $this->error(sprintf(
                "unknown profile '%s', expected one of: %s",
                $queueOption,
                implode(', ', $profile->names())
            ));

            return self::INVALID;
        }

        $queueName = $profile->queueName($queueOption);
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

        $connection = $this->amqpFactory->connection();

        // Проверяем существование очереди на отдельном probe-channel ДО basic_consume.
        // После 404 RabbitMQ закрывает channel, поэтому используется именно
        // отдельный probe (основной channel создаётся ниже).
        try {
            $probeChannel = $connection->channel();
            $probeChannel->queue_declare($queueName, true);
            $probeChannel->close();
            // @phpstan-ignore-next-line catch.neverThrown - php-amqplib не объявляет throws, но реально бросает на 404.
        } catch (AMQPProtocolChannelException $e) {
            if ($e->amqp_reply_code === 404) {
                $this->logger->error(
                    "queue '{$queueName}' not declared in broker, request topology change from DevOps",
                    [
                        'queue' => $queueName,
                        'delivery_profile' => $queueOption,
                        'reply_code' => $e->amqp_reply_code,
                    ],
                );
            } else {
                $this->logger->error(
                    'passive_declare failed: '.$e->amqp_reply_text,
                    [
                        'queue' => $queueName,
                        'delivery_profile' => $queueOption,
                        'reply_code' => $e->amqp_reply_code,
                    ],
                );
            }
            $this->amqpFactory->close();

            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->logger->error(
                'cannot probe queue: '.$e->getMessage(),
                [
                    'queue' => $queueName,
                    'delivery_profile' => $queueOption,
                    'exception' => $e::class,
                ],
            );
            $this->amqpFactory->close();

            return self::FAILURE;
        }

        $channel = $connection->channel();
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
