# Notification Service

REST API сервис для отправки уведомлений.

---

## Локальная разработка

### Быстрый старт

1. Готовим переменные окружения:
    ```bash
    cp .env.example .env
    ```

2. Запуск сервисов:
    ```bash
    docker compose up -d
    ```

3. Зависимости, ключ, миграции (одна команда):
    ```bash
    docker compose exec -it php bash -c "composer install" \
    && docker compose exec -it php bash -c "php artisan key:generate" \
    && docker compose exec -it php bash -c "php artisan migrate"
    ```

4. Проверьте работу: http://localhost:8080/api/health/live  
Должен быть ответ:
    ```json
    {"status":"pass","version":"1.0.0","serviceId":"notification-service"}
    ```


### Команды качества кода

1. Запуск тестов:
    ```bash
    docker compose exec -it php bash -c "composer test"
    ```
   
2. Статический анализ PHPStan:
    ```bash
    docker compose exec -it php bash -c "composer stan"
    ```
   
3. Линтер Laravel Pint:
    ```bash
    docker compose exec -it php bash -c "vendor/bin/pint --test"
    ```

### Прочее

1. Остановка сервисов
    ```bash
    docker compose down
    ```

### Документация

---

#### Swagger

Swagger работает только вне production-окружения (`APP_ENV` не равно `'production'`).

- Swagger UI: http://localhost:8080/api/documentation
- OpenAPI JSON: http://localhost:8080/api/openapi.json

Команда регенерации спецификации:
```bash
docker compose exec -it php bash -c "php artisan l5-swagger:generate"
```

---

#### Есть готовые Postman коллекции

1. Откройте Postman. Выберите в самом верхнем меню `File`, потом `Import`.
2. Выберите сразу 2 файла из директории `docs/postman/` в проекте:
   - `Notification Service.postman_collection.json` - коллекция запросов.
   - `Notification Service.postman_environment.json` - переменные окружения.
3. После того, как у вас появится коллекция `Notification Service` в левом сайдбаре, 
выберите её и справа сверху в выпадающем списке (будет написано `No environment`) выберите
`Notification Service` (название набора переменных окружения такое же). После этого 
переменные добавятся к запросам.
4. Значение переменной `api_token` (`.env` > `API_TOKEN`) уже добавлено от dev версии 
(`.env.example` > `API_TOKEN`), поэтому вам ничего делать не нужно, если только 
вы не поставили в `.env` свой токен. Если так, то установите его в environment `Notification Service`
