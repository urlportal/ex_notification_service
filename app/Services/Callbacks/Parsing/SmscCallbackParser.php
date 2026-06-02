<?php

namespace App\Services\Callbacks\Parsing;

use App\Dto\ProviderCallbackDto;
use App\Enums\NotificationChannel;
use App\Enums\ProviderCallbackStatus;
use App\Exceptions\Callbacks\InvalidCallbackPayloadException;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Request;

class SmscCallbackParser implements CallbackPayloadParserInterface
{
    private const DELIVERED_STATUSES = [1];

    private const DROPPED_STATUSES = [3, 20, 25, 29];

    private const INTERMEDIATE_STATUSES = [0, 2];

    private const MOSCOW_TZ = 'Europe/Moscow';

    public function parse(Request $request, NotificationChannel $channel): ?ProviderCallbackDto
    {
        $data = $request->all();

        $providerMessageId = $data['id'] ?? null;
        if ($providerMessageId === null || $providerMessageId === '') {
            throw new InvalidCallbackPayloadException('Отсутствует обязательное поле id');
        }

        $statusCode = isset($data['status']) ? (int) $data['status'] : null;

        if ($statusCode === null || in_array($statusCode, self::INTERMEDIATE_STATUSES, true)) {
            return null;
        }

        if (in_array($statusCode, self::DELIVERED_STATUSES, true)) {
            return new ProviderCallbackDto(
                providerMessageId: (string) $providerMessageId,
                status: ProviderCallbackStatus::Delivered,
                channel: $channel,
                error: null,
                occurredAt: $this->parseOccurredAt($data['last_date'] ?? null),
            );
        }

        if (in_array($statusCode, self::DROPPED_STATUSES, true)) {
            $error = $data['err'] ?? null;
            if ($error === null || $error === '') {
                throw new InvalidCallbackPayloadException(
                    'Отсутствует поле err для статуса Dropped'
                );
            }

            return new ProviderCallbackDto(
                providerMessageId: (string) $providerMessageId,
                status: ProviderCallbackStatus::Dropped,
                channel: $channel,
                error: (string) $error,
                occurredAt: $this->parseOccurredAt($data['last_date'] ?? null),
            );
        }

        // Неизвестный статус — промежуточный по умолчанию
        return null;
    }

    private function parseOccurredAt(?string $lastDate): DateTimeImmutable
    {
        if ($lastDate === null || $lastDate === '') {
            return new DateTimeImmutable('now', new DateTimeZone('UTC'));
        }

        $moscowTime = DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s',
            $lastDate,
            new DateTimeZone(self::MOSCOW_TZ)
        );

        if ($moscowTime === false) {
            return new DateTimeImmutable('now', new DateTimeZone('UTC'));
        }

        return $moscowTime->setTimezone(new DateTimeZone('UTC'));
    }
}
