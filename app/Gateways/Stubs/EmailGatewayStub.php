<?php

namespace App\Gateways\Stubs;

use App\Gateways\EmailGatewayInterface;
use App\Gateways\Exceptions\PermanentGatewayException;
use App\Gateways\Exceptions\TemporaryGatewayException;
use App\Gateways\GatewayResponse;
use App\Gateways\RandomProviderInterface;
use App\Models\Notification;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;

class EmailGatewayStub implements EmailGatewayInterface
{
    public function __construct(private readonly RandomProviderInterface $random) {}

    public function send(Notification $notification): GatewayResponse
    {
        // Нужно имитировать неисправимый, временный сбой отправки или успешную отправку.

        // Получаем значения от 0 до 1, которые изменяют шанс на сбой.
        [$permanentFailureRate, $temporaryFailureRate] = $this->resolveRates();

        // Генерируем случайное число от 0 до 1, которое даст нужное поведение в рамках данной отправки.
        $chance = $this->random->nextFloat();

        if ($chance < $permanentFailureRate) {
            Log::warning('email-gateway-stub: permanent failure', [
                'notification_id' => $notification->id,
                'channel' => 'email',
                'decision' => 'permanent_failure',
            ]);

            throw new PermanentGatewayException('Email gateway stub: permanent failure');
        }

        if ($chance < $permanentFailureRate + $temporaryFailureRate) {
            Log::warning('email-gateway-stub: temporary failure', [
                'notification_id' => $notification->id,
                'channel' => 'email',
                'decision' => 'temporary_failure',
            ]);

            throw new TemporaryGatewayException('Email gateway stub: temporary failure');
        }

        $this->simulateLatency();

        Log::info('email-gateway-stub: success', [
            'notification_id' => $notification->id,
            'channel' => 'email',
            'decision' => 'success',
        ]);

        return new GatewayResponse(
            providerMessageId: Uuid::uuid7()->toString(),
            acceptedAt: new \DateTimeImmutable,
        );
    }

    /**
     * Вернёт границы шансов на неисправимый и временный сбой отправки сообщения
     *
     * @return array{float, float}
     */
    private function resolveRates(): array
    {
        // Тут добавлено исправление невалидного значения (если x<0 или x>1). В таком случае подставляется коректное ближайшее значение (0 или 1).
        $permanentFailureRate = max(0.0, min(1.0, config('gateway.stubs.perm_failure_rate')));
        $temporaryFailureRate = max(0.0, min(1.0 - $permanentFailureRate, min(1.0, config('gateway.stubs.temp_failure_rate'))));

        return [$permanentFailureRate, $temporaryFailureRate];
    }

    /**
     * Имитирует сетевую задержку провайдера
     *
     * @return void
     */
    private function simulateLatency(): void
    {
        // Гарантируем, что значения неотрицательны и $maxMs >= $minMs
        $minMs = max(0, config('gateway.stubs.min_latency_ms'));
        $maxMs = max($minMs, config('gateway.stubs.max_latency_ms'));

        if ($maxMs === 0) {
            return;
        }

        // Генерация рандомного значения в диапазоне от $minMs до $maxMs
        $delayMs = (int) round($minMs + ($maxMs - $minMs) * $this->random->nextFloat());
        usleep($delayMs * 1000);
    }
}
