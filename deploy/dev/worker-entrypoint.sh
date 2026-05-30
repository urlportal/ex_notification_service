#!/bin/sh
set -e

# Генерируем секции sender-* по конфигу приложения.
mkdir -p /etc/supervisor/conf.d
php /app/artisan supervisor:render-sender --output=/etc/supervisor/conf.d/sender.conf

# Передаём управление supervisord (PID 1 корректно реагирует на SIGTERM от docker stop).
exec supervisord -n -c /etc/supervisord.conf
