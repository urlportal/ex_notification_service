<?php

namespace App\Exceptions\Messaging;

/**
 * Конфиг профилей доставки (config/notifications -> delivery_profiles) невалиден.
 * Бросается при boot конструктором DeliveryProfile: пустой список, либо одно
 * или несколько нарушений (имя не соответствует ^[a-z][a-z0-9_]*$,
 * queue пустой, workers/prefetch меньше 1). В нормальной работе не
 * перехватывается приложением, обнаруживается до начала обработки запросов.
 */
class InvalidDeliveryProfileException extends \DomainException {}
