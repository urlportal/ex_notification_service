FROM php:8.4-fpm-alpine

ARG CONTAINER_EXEC_USER_ID=1000
ARG CONTAINER_EXEC_GROUP_ID=1000

RUN apk update --no-check-certificate && apk add --no-cache --allow-untrusted ca-certificates && update-ca-certificates

RUN apk add --no-cache shadow && \
    groupmod -o -g $CONTAINER_EXEC_GROUP_ID www-data && \
    usermod -o -u $CONTAINER_EXEC_USER_ID -g www-data www-data && \
    apk del shadow;

COPY --from=composer:2.9.5 /usr/bin/composer /usr/local/bin/composer

RUN apk update \
    && apk upgrade --available \
    && apk add --no-cache \
    bash \
    git \
    unzip \
    linux-headers \
    supervisor \
    postgresql-dev \
    icu-dev \
    icu-libs \
    rabbitmq-c \
    rabbitmq-c-dev \
    $PHPIZE_DEPS

RUN docker-php-ext-install -j$(nproc) \
    pdo_pgsql \
    intl \
    bcmath \
    sockets \
    pcntl \
    opcache

RUN pecl channel-update pecl.php.net \
    && pecl install redis amqp xdebug-3.5.0 \
    && rm -rf /tmp/pear \
    && docker-php-ext-enable redis amqp xdebug

RUN echo "xdebug.mode=debug" >> "$PHP_INI_DIR/conf.d/docker-php-ext-xdebug.ini" \
    && echo "xdebug.start_with_request=trigger" >> "$PHP_INI_DIR/conf.d/docker-php-ext-xdebug.ini" \
    && echo "xdebug.client_host=host.docker.internal" >> "$PHP_INI_DIR/conf.d/docker-php-ext-xdebug.ini" \
    && echo "xdebug.client_port=9003" >> "$PHP_INI_DIR/conf.d/docker-php-ext-xdebug.ini" \
    && echo "xdebug.log=/var/log/xdebug.log" >> "$PHP_INI_DIR/conf.d/docker-php-ext-xdebug.ini" \
    && touch /var/log/xdebug.log && chmod 600 /var/log/xdebug.log \
    && mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini" \
    && git config --system --add safe.directory /app

COPY deploy/dev/supervisor/supervisord.conf /etc/supervisord.conf

WORKDIR /app

CMD ["supervisord", "-n", "-c", "/etc/supervisord.conf"]
