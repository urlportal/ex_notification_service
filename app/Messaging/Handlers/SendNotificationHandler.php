<?php

namespace App\Messaging\Handlers;

use App\Enums\NotificationStatus;
use App\Gateways\Exceptions\PermanentGatewayException;
use App\Gateways\Exceptions\TemporaryGatewayException;
use App\Gateways\GatewayFactory;
use App\Logging\RequestIdContext;
use App\Messaging\AbstractIdempotentMessageHandler;
use App\Messaging\Inbox\MsgInboxRepositoryInterface;
use App\Messaging\Messages\SendNotificationCommand;
use App\Repositories\NotificationRepositoryInterface;
use Illuminate\Database\DatabaseManager;
use PhpAmqpLib\Message\AMQPMessage;
use Psr\Log\LoggerInterface;

/**
 * Обрабатывает send-команды из приоритетной очереди.
 *
 * Одна реализация обслуживает два consumer (`sender-transactional`
 * или `sender-marketing`). Приходит параметром конструктора. Promoted-свойство
 * заполняется до тела конструктора, поэтому валидация kebab-case в базовом
 * AbstractIdempotentMessageHandler видит значение.
 */
class SendNotificationHandler extends AbstractIdempotentMessageHandler
{
    public function __construct(
        MsgInboxRepositoryInterface $inbox,
        DatabaseManager $db,
        LoggerInterface $logger,
        RequestIdContext $requestIdContext,
        private readonly string $consumerName,
        private readonly NotificationRepositoryInterface $notificationRepo,
        private readonly GatewayFactory $gatewayFactory,
    ) {
        parent::__construct($inbox, $db, $logger, $requestIdContext);
    }

    protected function consumerName(): string
    {
        return $this->consumerName;
    }

    protected function handleInternal(AMQPMessage $message, string $messageId, array $headers): void
    {
        // 1. Разбор. Битый payload бросит InvalidSendCommandException -> базовый
        // handle() сделает nack(requeue=false) -> DLQ.
        $command = SendNotificationCommand::fromArray(json_decode($message->getBody(), true));

        // 2. Номер попытки из заголовков (0, если отсутствует).
        $retryAttempt = (int) ($headers['retry_attempt'] ?? 0);

        // 3. SELECT ... FOR UPDATE внутри транзакции базового класса.
        $notification = $this->notificationRepo->lockForUpdateById($command->notificationId);

        if ($notification === null) {
            $this->logger->warning('notification not found', [
                'notification_id' => $command->notificationId,
                'consumer_name' => $this->consumerName,
            ]);

            return;
        }

        // 4. Уже финализированное уведомление пропускаем (не вызываем gateway).
        if ($notification->status !== NotificationStatus::Queued) {
            $this->logger->info('already finalized: '.$notification->status->value, [
                'notification_id' => (string) $notification->id,
                'status' => $notification->status->value,
            ]);

            return;
        }

        // 5. Вызов gateway внутри той же транзакции и под блокировкой строки.
        $gateway = $this->gatewayFactory->make($notification->channel);

        try {
            $response = $gateway->send($notification);
            $this->notificationRepo->updateOnSent(
                id: (string) $notification->id,
                providerMessageId: $response->providerMessageId,
                sentAt: now()->toDateTimeImmutable(),
                attempts: $retryAttempt + 1,
            );
            $this->logger->info('notification sent', [
                'notification_id' => (string) $notification->id,
                'channel' => $notification->channel->value,
                'priority' => $notification->priority->value,
                'provider_message_id' => $response->providerMessageId,
                'attempts' => $retryAttempt + 1,
            ]);
        } catch (PermanentGatewayException|TemporaryGatewayException $e) {
            // Временная ошибка ведёт себя как постоянная.
            // Ветка TemporaryGatewayException будет заменена на retry-публикацию.
            $this->notificationRepo->updateOnDropped(
                id: (string) $notification->id,
                droppedAt: now()->toDateTimeImmutable(),
                lastError: $e->getMessage(),
                attempts: $retryAttempt + 1,
            );
            $this->logger->warning('notification dropped', [
                'notification_id' => (string) $notification->id,
                'reason' => $e::class,
                'attempts' => $retryAttempt + 1,
            ]);
        }

        // Любой иной Throwable (ошибка БД, неизвестный channel в GatewayFactory
        // и т.п.) не ловится здесь и пробрасывается -> базовый handle() сделает
        // nack(requeue=false) -> DLQ; транзакция откатывается, уведомление
        // остаётся queued (не помечается ложно dropped).
    }
}
