<?php

namespace App\Messaging\Outbox;

/**
 * Статусы жизненного цикла строки в таблице msg_outbox.
 *
 * pending   — сообщение ожидает публикации в RabbitMQ.
 * published — брокер прислал ack; сообщение доставлено (терминальный статус).
 * failed    — брокер вернул nack или basic.return, либо payload невалиден (терминальный статус).
 *
 * Строки в статусе pending подбираются relay-воркером через SELECT … FOR UPDATE SKIP LOCKED.
 * Строки в статусе failed не подбираются автоматически
 * Строки, не получившие confirm в течение таймаута, остаются в pending (at-least-once).
 */
enum OutboxStatus: string
{
    case Pending = 'pending';
    case Published = 'published';
    case Failed = 'failed';
}
