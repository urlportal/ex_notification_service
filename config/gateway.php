<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Gateway Stub Configuration
    |--------------------------------------------------------------------------
    |
    | Параметры поведения stub-провайдеров уведомлений (SMS, Email).
    | Предназначены только для разработки и тестирования.
    | В production-окружении (APP_ENV=production) не регистрируются.
    |
    */

    'stubs' => [

        // Вероятность временной ошибки провайдера от 0.0 до 1.0.
        // При превышении — бросает TemporaryGatewayException (нужен retry).
        'temp_failure_rate' => (float) env('GATEWAY_STUB_TEMP_FAILURE_RATE', 0.1),

        // Вероятность постоянной ошибки провайдера от 0.0 до 1.0.
        // При превышении — бросает PermanentGatewayException (retry бессмысленен).
        'perm_failure_rate' => (float) env('GATEWAY_STUB_PERM_FAILURE_RATE', 0.02),

        // Минимальная имитируемая задержка провайдера в миллисекундах.
        'min_latency_ms' => (int) env('GATEWAY_STUB_MIN_LATENCY_MS', 10),

        // Максимальная имитируемая задержка провайдера в миллисекундах.
        'max_latency_ms' => (int) env('GATEWAY_STUB_MAX_LATENCY_MS', 50),

    ],

];
