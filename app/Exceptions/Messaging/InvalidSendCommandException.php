<?php

namespace App\Exceptions\Messaging;

/**
 * Битый payload send-команды (`notifications:send`): не-JSON, отсутствует
 * `notification_id`, неверный тип или пустая строка. Имя общее, без привязки
 * к полю; базовый AbstractIdempotentMessageHandler по этому исключению делает
 * nack(requeue=false) -> DLQ.
 */
class InvalidSendCommandException extends \RuntimeException {}
