<?php

namespace Tests\Feature\Messaging\Sender;

use App\Gateways\GatewayResponse;
use App\Models\Notification;

/**
 * Помощник для тестов: считает вызовы gateway-double и хранит поведение
 * успешной отправки (или бросаемое исключение через callable).
 */
class GatewaySpy
{
    public int $calls = 0;

    /** @var list<string> id уведомлений, переданных в send() */
    public array $sent = [];

    /** @var callable(Notification): GatewayResponse */
    public $behavior;

    /**
     * @param  callable(Notification): GatewayResponse  $behavior
     */
    public function __construct(callable $behavior)
    {
        $this->behavior = $behavior;
    }

    public function record(Notification $notification): GatewayResponse
    {
        $this->calls++;
        $this->sent[] = (string) $notification->id;

        return ($this->behavior)($notification);
    }
}
