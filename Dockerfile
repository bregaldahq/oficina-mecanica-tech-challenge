# Stage 1: instala dependências de produção sem ferramentas de dev
FROM composer:2.7 AS vendor

WORKDIR /app

COPY composer.json composer.lock* ./

RUN composer install \
    --no-dev \
    --no-scripts \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader

# Stage 2: imagem de desenvolvimento com Composer para testes e análise
FROM php:8.2-fpm-alpine AS dev

RUN apk add --no-cache \
    linux-headers \
    $PHPIZE_DEPS \
    && docker-php-ext-install pdo pdo_mysql \
    && apk del $PHPIZE_DEPS

COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer

COPY docker/php/php.ini /usr/local/etc/php/conf.d/custom.ini

WORKDIR /var/www/html

EXPOSE 9000

CMD ["php-fpm"]

# Stage 3: imagem de produção sem Composer nem ferramentas de dev
FROM php:8.2-fpm-alpine AS production

LABEL maintainer="oficina-mecanica-api"

RUN apk add --no-cache \
    linux-headers \
    $PHPIZE_DEPS \
    && docker-php-ext-install pdo pdo_mysql \
    && apk del $PHPIZE_DEPS

COPY docker/php/php.ini /usr/local/etc/php/conf.d/custom.ini

WORKDIR /var/www/html

COPY --from=vendor /app/vendor ./vendor
COPY src ./src
COPY public ./public
COPY docs ./docs
COPY swagger.yaml ./swagger.yaml

RUN chown -R www-data:www-data /var/www/html

EXPOSE 9000

CMD ["php-fpm"]
