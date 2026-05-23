<?php

namespace App\Gateways;

use App\Gateways\Exceptions\PermanentGatewayException;
use App\Gateways\Exceptions\TemporaryGatewayException;
use App\Models\Notification;

interface NotificationGatewayInterface
{
    /**
     * @throws TemporaryGatewayException Провайдер временно недоступен (нужен retry)
     * @throws PermanentGatewayException Постоянная ошибка (retry бессмысленен)
     */
    public function send(Notification $notification): GatewayResponse;
}
