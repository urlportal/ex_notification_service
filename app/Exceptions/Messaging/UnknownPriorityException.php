<?php

namespace App\Exceptions\Messaging;

/**
 * Обращение к DeliveryProfile по имени, которого нет
 * (queueName/get). Это баг кода, а не невалидный конфиг.
 */
class UnknownPriorityException extends \DomainException {}
