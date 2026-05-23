<?php

namespace App\Gateways;

use App\Enums\NotificationChannel;
use Illuminate\Contracts\Container\Container;

class GatewayFactory
{
    /** @var array<string, class-string<NotificationGatewayInterface>> */
    private static array $channelMap = [
        'sms' => SmsGatewayInterface::class,
        'email' => EmailGatewayInterface::class,
    ];

    public function __construct(private readonly Container $app) {}

    public function make(NotificationChannel $channel): NotificationGatewayInterface
    {
        $class = self::$channelMap[$channel->value] ?? null;

        if ($class === null) {
            throw new \InvalidArgumentException(
                "No gateway registered for channel [{$channel->value}]."
            );
        }

        return $this->app->make($class);
    }
}
