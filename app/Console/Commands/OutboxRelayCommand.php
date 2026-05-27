<?php

namespace App\Console\Commands;

use App\Messaging\AmqpConnectionFactory;
use App\Messaging\Outbox\MsgOutboxRepositoryInterface;
use App\Messaging\Outbox\OutboxMessagePublisher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Artisan-команда, реализующая Outbox Relay.
 *
 * Алгоритм одной итерации цикла:
 *  1. Открыть транзакцию БД.
 *  2. Выбрать batch pending-строк через SELECT...FOR UPDATE SKIP LOCKED.
 *  3. Если строк нет, закоммитить (транзакция пустая) и «уснуть» на idle-sleep-ms. После продолжить.
 *  4. Опубликовать батч в RabbitMQ с publisher confirms.
 *  5. Маркировать published/failed. Unconfirmed остаются pending (at-least-once).
 *  6. Закоммитить.
 *
 * При исключении из publishBatch (брокер недоступен) исключение выходит из closure,
 * Laravel откатывает транзакцию, строки остаются pending, команда завершается
 * с ненулевым кодом. Supervisord перезапустит процесс.
 *
 * Graceful shutdown: SIGTERM/SIGINT выставляют флаг $shouldStop. Текущий батч
 * доводится до конца, после чего цикл прерывается и соединение AMQP закрывается.
 */
class OutboxRelayCommand extends Command
{
    protected $signature = 'messaging:outbox-relay
        {--batch-size=100 : Размер батча pull/publish/mark}
        {--idle-sleep-ms=500 : Пауза (мс) при пустом outbox}
        {--max-iterations=0 : Максимум итераций (0 = бесконечно; только для тестов)}';

    protected $description = 'Публикует pending-сообщения из msg_outbox в RabbitMQ (Outbox Relay).';

    public function __construct(
        private readonly MsgOutboxRepositoryInterface $repo,
        private readonly OutboxMessagePublisher $publisher,
        private readonly AmqpConnectionFactory $amqpFactory,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $batchSize = (int) $this->option('batch-size');
        $idleSleepUs = (int) $this->option('idle-sleep-ms') * 1000;
        $maxIterations = (int) $this->option('max-iterations');
        $iteration = 0;
        $shouldStop = false;

        // Асинхронная доставка сигналов — обработчик выполняется между опкодами PHP.
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, function () use (&$shouldStop): void {
            $shouldStop = true;
        });
        pcntl_signal(SIGINT, function () use (&$shouldStop): void {
            $shouldStop = true;
        });

        while (! $shouldStop) {
            if ($maxIterations > 0 && $iteration >= $maxIterations) {
                break;
            }

            // Флаг объявляется до closure и захватывается по ссылке,
            // иначе изменение внутри closure не будет видно снаружи.
            $isIdle = false;

            DB::transaction(function () use ($batchSize, &$isIdle): void {
                $rows = $this->repo->pullPendingBatch($batchSize);

                if (empty($rows)) {
                    $isIdle = true;

                    return;
                }

                $result = $this->publisher->publishBatch(...$rows);

                if (! empty($result->publishedIds)) {
                    $this->repo->markPublishedMany($result->publishedIds);
                }

                foreach ($result->failedIds as $failedId) {
                    $this->repo->markFailed($failedId, 'unrouted or nack');
                }

                // unconfirmedIds не трогаем: строки остаются pending
                // и будут обработаны на следующей итерации (at-least-once).
            });

            if ($isIdle) {
                usleep($idleSleepUs);
            }

            $iteration++;
        }

        // Закрываем AMQP-соединение после завершения цикла (graceful shutdown).
        $this->amqpFactory->close();

        return self::SUCCESS;
    }
}
