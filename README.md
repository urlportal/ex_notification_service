# Notification Service

REST API сервис для отправки уведомлений.

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
