<?php

namespace Tests\Feature\Messaging;

use App\Enums\NotificationPriority;
use App\Messaging\AmqpConnectionFactory;
use App\Messaging\NotificationPublisherInterface;
use App\Models\Notification;
use PhpAmqpLib\Message\AMQPMessage;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

class NotificationPublisherTest extends TestCase
{
    private NotificationPublisherInterface $publisher;

    private AmqpConnectionFactory $factory;

    private const QUEUES_TO_DRAIN = [
        'notifications.transactional',
        'notifications.marketing',
        'notifications.retry.1',
        'notifications.retry.2',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->factory = app(AmqpConnectionFactory::class);
        $this->publisher = app(NotificationPublisherInterface::class);
        $this->drainQueues();
    }

    private function drainQueues(): void
    {
        $channel = $this->factory->connection()->channel();
        foreach (self::QUEUES_TO_DRAIN as $queue) {
            while ($channel->basic_get($queue, no_ack: true) !== null) {
            }
        }
        $channel->close();
    }

    private function makeNotification(NotificationPriority $priority): Notification
    {
        $notification = new Notification;
        $notification->id = Uuid::uuid7()->toString();
        $notification->priority = $priority;

        return $notification;
    }

    public function test_initial_send_transactional_routes_to_correct_queue(): void
    {
        $notification = $this->makeNotification(NotificationPriority::Transactional);
        $requestId = Uuid::uuid7()->toString();

        $this->publisher->publishForInitialSend($notification, $requestId);

        $channel = $this->factory->connection()->channel();
        $message = $channel->basic_get('notifications.transactional', no_ack: true);
        $channel->close();

        $this->assertNotNull($message);
        $this->assertSame(['notification_id' => $notification->id], json_decode($message->body, true));

        $headers = $message->get('application_headers')->getNativeData();
        $this->assertSame($requestId, $headers['request_id']);
        $this->assertSame(0, $headers['retry_attempt']);
        $this->assertSame(AMQPMessage::DELIVERY_MODE_PERSISTENT, $message->get('delivery_mode'));
    }

    public function test_initial_send_marketing_routes_to_correct_queue(): void
    {
        $notification = $this->makeNotification(NotificationPriority::Marketing);
        $requestId = Uuid::uuid7()->toString();

        $this->publisher->publishForInitialSend($notification, $requestId);

        $channel = $this->factory->connection()->channel();
        $message = $channel->basic_get('notifications.marketing', no_ack: true);
        $channel->close();

        $this->assertNotNull($message);
        $this->assertSame(['notification_id' => $notification->id], json_decode($message->body, true));
    }

    public function test_retry_routes_to_retry_2_queue(): void
    {
        $notification = $this->makeNotification(NotificationPriority::Transactional);
        $requestId = Uuid::uuid7()->toString();

        $this->publisher->publishForRetry($notification, 2, $requestId);

        $channel = $this->factory->connection()->channel();
        $message = $channel->basic_get('notifications.retry.2', no_ack: true);
        $channel->close();

        $this->assertNotNull($message);
        $this->assertSame('2', $message->delivery_info['routing_key']);

        $headers = $message->get('application_headers')->getNativeData();
        $this->assertSame($requestId, $headers['request_id']);
        $this->assertSame(2, $headers['retry_attempt']);
    }

    public function test_retry_routes_to_retry_1_queue(): void
    {
        $notification = $this->makeNotification(NotificationPriority::Transactional);
        $requestId = Uuid::uuid7()->toString();

        $this->publisher->publishForRetry($notification, 1, $requestId);

        $channel = $this->factory->connection()->channel();
        $message = $channel->basic_get('notifications.retry.1', no_ack: true);
        $channel->close();

        $this->assertNotNull($message);
        $this->assertSame('1', $message->delivery_info['routing_key']);
    }
}
