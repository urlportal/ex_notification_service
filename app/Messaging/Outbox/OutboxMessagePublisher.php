<?php

namespace App\Messaging\Outbox;

use App\Messaging\AmqpConnectionFactory;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Psr\Log\LoggerInterface;

/**
 * Publisher исходящих AMQP-сообщений из outbox.
 *
 * Реализует пакетную публикацию с publisher confirms, mandatory-флагом
 * и return-listener. Каждый вызов publishBatch() открывает отдельный
 * channel с confirm_select() и закрывает его в finally.
 *
 * Гарантии at-least-once:
 *  - published   - брокер прислал ack. Вызывающая сторона выполняет markPublishedMany().
 *  - failed      - basic.return (mandatory) или nack. Выполняется markFailed().
 *  - unconfirmed - timeout ожидания подтверждений. Строки остаются 'pending' и будут обработаны на следующей итерации.
 *
 * @see OutboxRelayCommand использует этот класс для публикации батчей.
 */
class OutboxMessagePublisher
{
    public function __construct(
        private readonly AmqpConnectionFactory $factory,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Опубликует набор outbox строк в RabbitMQ с publisher confirms.
     *
     * @param  MsgOutbox  ...$rows  Строки из msg_outbox в статусе pending.
     * @return PublishBatchResult Разбивка на published/failed/unconfirmed.
     *
     * @throws \Throwable При фатальном сбое AMQP-соединения. Соединение сбрасывается.
     */
    public function publishBatch(MsgOutbox ...$rows): PublishBatchResult
    {
        if (empty($rows)) {
            return new PublishBatchResult([], [], []);
        }

        $channel = null;

        try {
            $channel = $this->factory->connection()->channel();
            $channel->confirm_select();

            /** @var list<string> $publishedIds */
            $publishedIds = [];
            /** @var list<string> $nackIds */
            $nackIds = [];
            /** @var list<string> $returnedIds */
            $returnedIds = [];
            /** @var list<string> $jsonFailedIds */
            $jsonFailedIds = [];
            /** @var list<string> $publishedBatchIds ID строк, которые реально отправлены брокеру */
            $publishedBatchIds = [];

            // Ack-handler: брокер подтвердил приём сообщения.
            $channel->set_ack_handler(static function (AMQPMessage $message) use (&$publishedIds): void {
                $publishedIds[] = (string) $message->get('message_id');
            });

            // Nack-handler: брокер отклонил сообщение.
            $channel->set_nack_handler(static function (AMQPMessage $message) use (&$nackIds): void {
                $nackIds[] = (string) $message->get('message_id');
            });

            // Return-listener: сообщение не нашло очереди (mandatory=true).
            $channel->set_return_listener(function (
                int $replyCode,
                string $replyText,
                string $exchange,
                string $routingKey,
                AMQPMessage $message,
            ) use (&$returnedIds): void {
                $messageId = (string) $message->get('message_id');
                $returnedIds[] = $messageId;

                $this->logger->error('Unrouted AMQP message (basic.return)', [
                    'message_id' => $messageId,
                    'exchange' => $exchange,
                    'routing_key' => $routingKey,
                    'reply_code' => $replyCode,
                    'reply_text' => $replyText,
                ]);
            });

            foreach ($rows as $row) {
                // Если будет JSON-ошибка, строка уходит в failed и публикация пропускается.
                try {
                    $body = json_encode($row->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                } catch (\JsonException $e) {
                    $this->logger->error('Failed to JSON-encode outbox payload', [
                        'message_id' => $row->id,
                        'error' => $e->getMessage(),
                    ]);
                    $jsonFailedIds[] = $row->id;

                    continue;
                }

                $message = new AMQPMessage($body, [
                    'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                    'content_type' => 'application/json',
                    'message_id' => $row->id,
                    'timestamp' => time(),
                    'application_headers' => new AMQPTable((array) $row->headers),
                ]);

                $channel->basic_publish($message, $row->exchange, $row->routing_key, true);
                $publishedBatchIds[] = $row->id;
            }

            // Ждём ack/return для всех опубликованных сообщений.
            // При AMQPTimeoutException часть сообщений не подтверждена. Они попадут
            // в unconfirmedIds и останутся pending для следующей итерации (at-least-once).
            try {
                $channel->wait_for_pending_acks_returns(5.0);
            } catch (AMQPTimeoutException) {
                $this->logger->warning('Publisher confirms timeout: some messages unconfirmed', [
                    'total_sent' => count($publishedBatchIds),
                    'confirmed' => count($publishedIds) + count($nackIds) + count($returnedIds),
                ]);
            }

            // Сообщения basic.return считаются failed (ошибка топологии).
            $failedIds = array_merge($jsonFailedIds, $nackIds, $returnedIds);

            // unconfirmed = отправлено - (acked + nacked + returned).
            $confirmedOrFailed = array_merge($publishedIds, $nackIds, $returnedIds);
            $unconfirmedIds = array_values(array_diff($publishedBatchIds, $confirmedOrFailed));

            return new PublishBatchResult(
                publishedIds: $publishedIds,
                failedIds: $failedIds,
                unconfirmedIds: $unconfirmedIds,
            );
        } catch (\Throwable $e) {
            // Сброс соединения при любом фатальном сбое (кроме timeout, который поймали выше).
            $this->factory->close();
            throw $e;
        } finally {
            if ($channel !== null && $channel->is_open()) {
                $channel->close();
            }
        }
    }
}
