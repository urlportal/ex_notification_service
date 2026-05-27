<?php

namespace App\Messaging\Outbox;

/**
 * Результат одной батч-публикации в OutboxMessagePublisher::publishBatch().
 *
 * Разбивает идентификаторы обработанных строк на три группы:
 *
 * publishedIds   - брокер прислал ack, значит строки можно маркировать как published.
 * failedIds      - брокер вернул nack или basic.return, либо ошибка сериализации, значит
 *                  строки маркируются как failed.
 * unconfirmedIds - таймаут ожидания confirms истёк без ack/nack/return, значит
 *                  строки остаются в pending (будут обработаны снова).
 */
readonly class PublishBatchResult
{
    /**
     * @param  string[]  $publishedIds  ID строк, получивших ack от брокера.
     * @param  string[]  $failedIds  ID строк с nack, basic.return или JsonException.
     * @param  string[]  $unconfirmedIds  ID строк без confirm за таймаут (остались pending).
     */
    public function __construct(
        public array $publishedIds,
        public array $failedIds,
        public array $unconfirmedIds,
    ) {}
}
