<?php

use App\Services\Callbacks\Auth\IpWhitelistAuthenticator;
use App\Services\Callbacks\Auth\MailgunSignatureAuthenticator;
use App\Services\Callbacks\Parsing\MailgunCallbackParser;
use App\Services\Callbacks\Parsing\SmscCallbackParser;

return [
    'bulk' => [
        // Максимум subscriber_ids в одном запросе POST /api/v1/notifications/bulk.
        'max_subscribers' => (int) env('BULK_MAX_SUBSCRIBERS', 500),
    ],

    'bulk_expand' => [
        // Размер одного INSERT-батча при разворачивании bulk-expand-event.
        'chunk_size' => (int) env('BULK_EXPAND_CHUNK_SIZE', 100),
    ],

    // Профили доставки. Ключ массива - имя (snake_case), используется как
    // routing_key в notifications.exchange, имя program supervisord (sender-<name>)
    // и path-параметр URL. Поле queue - имя AMQP-очереди в брокере (может не
    // совпадать с именем профиля). Поля workers/prefetch определяют пул
    // воркеров sender-<name>: workers - numprocs supervisord,
    // prefetch - basic_qos(prefetch_count).
    // Как добавить новый профиль: записать сюда, объявить очередь и binding
    // (routing_key=<name>) в deploy/dev/rabbitmq/definitions.json,
    // пересобрать/перезапустить контейнер workers (секция supervisord генерируется
    // автоматически), прогнать php artisan notifications:priorities:validate.
    'delivery_profiles' => [
        'transactional' => [
            'queue' => env('RABBITMQ_QUEUE_TRANSACTIONAL', 'notifications.transactional'),
            'workers' => (int) env('SENDER_TX_NUMPROCS', 2),
            'prefetch' => (int) env('SENDER_TX_PREFETCH', 10),
        ],
        'marketing' => [
            'queue' => env('RABBITMQ_QUEUE_MARKETING', 'notifications.marketing'),
            'workers' => (int) env('SENDER_MK_NUMPROCS', 4),
            'prefetch' => (int) env('SENDER_MK_PREFETCH', 10),
        ],
    ],

    // Retry-механизм при временных сбоях gateway.
    // max_attempts - общее число попыток доставки (1 исходная + retry). По умолчанию 4.
    // levels - упорядоченные уровни backoff. Индекс массива (1-based) обозначает
    // номер только что выполненной попытки: после первой неудачи берётся levels[1],
    // после второй - levels[2] и так далее. key - суффикс routing_key retry-копии
    // (<key>.<priority>) и часть имени retry-очереди (notifications.retry.<key>.<priority>).
    // ttl_ms дублирует фактическое значение x-message-ttl соответствующей retry-очереди
    // в deploy/dev/rabbitmq/definitions.json. Приложение TTL не применяет, его задаёт
    // брокер. Изменение ttl_ms без правки definitions.json не повлияет на backoff.
    // Инвариант: count(levels) + 1 === max_attempts. Иначе RetryConfigServiceProvider
    // валит boot приложения.
    'retry' => [
        'max_attempts' => (int) env('RETRY_MAX_ATTEMPTS', 4),
        'levels' => [
            1 => ['key' => '30s', 'ttl_ms' => (int) env('RETRY_30S_TTL_MS', 30000)],
            2 => ['key' => '5m', 'ttl_ms' => (int) env('RETRY_5M_TTL_MS', 300000)],
            3 => ['key' => '30m', 'ttl_ms' => (int) env('RETRY_30M_TTL_MS', 1800000)],
        ],
    ],

    // Rescue sweep: периодическое восстановление уведомлений, потерявшихся из retry-очереди.
    // threshold_minutes: null = автовычисление в RescueSweepConfigServiceProvider как
    //   max(retry.levels.*.ttl_ms / 60000) + 15 мин. При явном задании через ENV значение
    //   должно превышать max TTL retry-очередей, иначе приложение не стартует.
    // batch_size: сколько записей выбирается из БД за одну итерацию цикла sweep.
    // max_process: максимальное число уведомлений, обрабатываемых за один запуск команды.
    // schedule_minutes: интервал запуска по расписанию Laravel Scheduler.
    //   Допустимы только делители 60 (1, 2, 3, 4, 5, 6, 10, 12, 15, 20, 30, 60), иначе приложение не стартует.
    'rescue_sweep' => [
        'threshold_minutes' => env('RESCUE_SWEEP_THRESHOLD_MINUTES') !== null
            ? (int) env('RESCUE_SWEEP_THRESHOLD_MINUTES')
            : null,
        'batch_size' => (int) env('RESCUE_SWEEP_BATCH_SIZE', 10),
        'max_process' => (int) env('RESCUE_SWEEP_MAX_PROCESS', 50),
        'schedule_minutes' => (int) env('RESCUE_SWEEP_SCHEDULE_MINUTES', 15),
    ],

    // Реестр адаптеров входящих webhook от провайдеров сообщений.
    // Ключ массива - имя адаптера (используется как path-параметр {adapter} в URL).
    // channel - канал доставки (sms/email), authenticator - класс проверки подлинности,
    // parser - класс разбора payload. Конфиг адаптера передаётся в verify() аргументом.
    // Добавить нового провайдера: добавить в массив ниже, создать authenticator и parser.
    'callbacks' => [
        'smsc' => [
            'channel' => 'sms',
            'authenticator' => IpWhitelistAuthenticator::class,
            'parser' => SmscCallbackParser::class,
            'allowed_cidrs' => env('PROVIDER_SMSC_CALLBACK_ALLOWED_CIDRS'),
        ],
        'mailgun' => [
            'channel' => 'email',
            'authenticator' => MailgunSignatureAuthenticator::class,
            'parser' => MailgunCallbackParser::class,
            'signing_key' => env('PROVIDER_MAILGUN_WEBHOOK_SIGNING_KEY'),
            'replay_window_seconds' => (int) env('PROVIDER_MAILGUN_CALLBACK_REPLAY_WINDOW', 300),
        ],
    ],

];
