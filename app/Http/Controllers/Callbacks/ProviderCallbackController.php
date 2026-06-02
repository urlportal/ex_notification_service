<?php

namespace App\Http\Controllers\Callbacks;

use App\Services\Callbacks\CallbackAdapterRegistry;
use App\Services\Callbacks\CallbackProcessingService;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

#[OA\Post(
    path: '/callbacks/{adapter}',
    description: <<<'DESC'
Публичный webhook-endpoint для приёма callback от провайдеров рассылки.
Аутентификация per-adapter:
- **mailgun**: HMAC-SHA256 подпись в теле запроса (поле `signature.signature`); заголовки аутентификации отсутствуют.
- **smsc**: IP-whitelist по CIDR (переменная `PROVIDER_SMSC_CALLBACK_ALLOWED_CIDRS`); заголовки аутентификации отсутствуют.

Тело запроса не имеет фиксированной схемы. Формат зависит от провайдера и нормализуется парсером адаптера.
Формат payload смотрите в документации соответствующего провайдера.
DESC,
    summary: 'Приём webhook-callback от провайдера рассылки',
    tags: ['Callbacks'],
    parameters: [
        new OA\Parameter(
            name: 'adapter',
            description: 'Ключ адаптера провайдера из реестра. Неизвестное значение отклоняется роутером с 404.',
            in: 'path',
            required: true,
            schema: new OA\Schema(type: 'string', enum: ['smsc', 'mailgun'])
        ),
        new OA\Parameter(
            name: 'X-Request-ID',
            description: 'UUID для сквозной трассировки. Если передан — используется; иначе генерируется UUID v7. Всегда присутствует в ответе.',
            in: 'header',
            required: false,
            schema: new OA\Schema(type: 'string', format: 'uuid')
        ),
    ],
    requestBody: new OA\RequestBody(
        required: true,
        description: 'Тело запроса в формате провайдера. Для mailgun — JSON Mailgun Webhooks API v3. Для smsc — form-data или query-string. Фиксированная схема отсутствует.',
        content: new OA\MediaType(
            mediaType: 'application/json',
            schema: new OA\Schema(type: 'object'),
            example: ['event-data' => ['event' => 'delivered', 'message' => ['headers' => ['message-id' => '<id@mailgun.org>']], 'timestamp' => 1700000000]]
        )
    ),
    responses: [
        new OA\Response(
            response: 204,
            description: 'Callback обработан: статус уведомления обновлён (с sent в delivered или с sent в dropped), либо событие провайдера не влияет на статус доставки (ignored).'
        ),
        new OA\Response(
            response: 200,
            description: 'Уведомление уже в финальном статусе (delivered/dropped). Состояние не изменено.',
            content: new OA\JsonContent(
                properties: [new OA\Property(property: 'message', type: 'string', example: 'Already finalized')]
            )
        ),
        new OA\Response(
            response: 401,
            description: 'Недействительная подпись (mailgun).',
            content: new OA\JsonContent(
                properties: [new OA\Property(property: 'message', type: 'string', example: 'Invalid signature')]
            )
        ),
        new OA\Response(
            response: 403,
            description: 'IP-адрес не в whitelist (smsc).',
            content: new OA\JsonContent(
                properties: [new OA\Property(property: 'message', type: 'string', example: 'Forbidden')]
            )
        ),
        new OA\Response(
            response: 404,
            description: 'Неизвестный адаптер (отклоняется роутером) или уведомление с указанным provider_message_id и каналом не найдено.',
            content: new OA\JsonContent(
                properties: [new OA\Property(property: 'message', type: 'string', example: 'Resource not found')]
            )
        ),
        new OA\Response(
            response: 409,
            description: 'Уведомление ещё не отправлено (статус queued). Callback пришёл преждевременно.',
            content: new OA\JsonContent(
                properties: [new OA\Property(property: 'message', type: 'string', example: 'Notification has not been sent yet')]
            )
        ),
        new OA\Response(
            response: 422,
            description: 'Некорректный payload: парсер не смог разобрать тело запроса.',
            content: new OA\JsonContent(
                properties: [new OA\Property(property: 'message', type: 'string', example: 'Invalid callback payload')]
            )
        ),
    ]
)]
class ProviderCallbackController
{
    public function __construct(
        private readonly CallbackAdapterRegistry $registry,
        private readonly CallbackProcessingService $processingService,
    ) {}

    public function __invoke(Request $request, string $adapter): Response
    {
        $parser = $this->registry->getParser($adapter);
        $channel = $this->registry->getChannel($adapter);

        $dto = $parser->parse($request, $channel);

        if ($dto === null) {
            return response()->noContent();
        }

        $result = $this->processingService->process($dto);

        return $result->toResponse();
    }
}
