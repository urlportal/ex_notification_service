<?php

namespace App\Services\Callbacks\Parsing;

use App\Dto\ProviderCallbackDto;
use App\Enums\NotificationChannel;
use App\Enums\ProviderCallbackStatus;
use App\Exceptions\Callbacks\InvalidCallbackPayloadException;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Request;

class MailgunCallbackParser implements CallbackPayloadParserInterface
{
    private const ANALYTICS_EVENTS = ['opened', 'clicked', 'unsubscribed', 'complained'];

    public function parse(Request $request, NotificationChannel $channel): ?ProviderCallbackDto
    {
        $body = $request->json()->all();

        $eventData = $body['event-data'] ?? null;
        if ($eventData === null) {
            throw new InvalidCallbackPayloadException('Отсутствует поле event-data');
        }

        $messageId = $eventData['message']['headers']['message-id'] ?? null;
        if ($messageId === null || $messageId === '') {
            throw new InvalidCallbackPayloadException('Отсутствует поле message-id');
        }

        $event = $eventData['event'] ?? null;

        if (in_array($event, self::ANALYTICS_EVENTS, true)) {
            return null;
        }

        $occurredAt = isset($eventData['timestamp'])
            ? (new DateTimeImmutable('@'.(int) $eventData['timestamp']))->setTimezone(new DateTimeZone('UTC'))
            : new DateTimeImmutable('now', new DateTimeZone('UTC'));

        if ($event === 'delivered') {
            return new ProviderCallbackDto(
                providerMessageId: (string) $messageId,
                status: ProviderCallbackStatus::Delivered,
                channel: $channel,
                error: null,
                occurredAt: $occurredAt,
            );
        }

        if (in_array($event, ['failed', 'rejected'], true)) {
            $error = $eventData['reason']
                ?? $eventData['delivery-status']['description']
                ?? '';

            return new ProviderCallbackDto(
                providerMessageId: (string) $messageId,
                status: ProviderCallbackStatus::Dropped,
                channel: $channel,
                error: (string) $error,
                occurredAt: $occurredAt,
            );
        }

        return null;
    }
}
