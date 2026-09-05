# syntax=docker/dockerfile:1

# ─────────────────────────────────────────────────────────────────────────────
# Stage 1: dependências de produção, sem ferramentas de desenvolvimento
# ─────────────────────────────────────────────────────────────────────────────
FROM composer:2.7 AS vendor

WORKDIR /app

COPY composer.json composer.lock* ./

RUN composer install \
    --no-dev \
    --no-scripts \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader

# ─────────────────────────────────────────────────────────────────────────────
# Stage 2: base comum (Debian bookworm — glibc é requisito do agente New Relic)
# ─────────────────────────────────────────────────────────────────────────────
FROM php:8.2-fpm-bookworm AS base

# Extensões PDO + agente PHP do New Relic (pacote oficial do repositório apt).
# O agente NÃO roda em musl/Alpine: por isso a imagem é bookworm.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends ca-certificates curl gnupg; \
    docker-php-ext-install -j"$(nproc)" pdo pdo_mysql; \
    curl -fsSL https://download.newrelic.com/548C16BF.gpg \
        | gpg --dearmor -o /usr/share/keyrings/newrelic-archive-keyring.gpg; \
    echo "deb [signed-by=/usr/share/keyrings/newrelic-archive-keyring.gpg] http://apt.newrelic.com/debian/ newrelic non-free" \
        > /etc/apt/sources.list.d/newrelic.list; \
    apt-get update; \
    NR_INSTALL_SILENT=1 apt-get install -y --no-install-recommends newrelic-php5; \
    NR_INSTALL_SILENT=1 newrelic-install install; \
    # O instalador cria um newrelic.ini com placeholders; ele é substituído
    # pelo arquivo versionado logo abaixo.
    rm -f /usr/local/etc/php/conf.d/newrelic.ini; \
    apt-get purge -y --auto-remove gnupg; \
    rm -rf /var/lib/apt/lists/*

COPY docker/php/php.ini /usr/local/etc/php/conf.d/custom.ini
COPY docker/php/newrelic.ini /usr/local/etc/php/conf.d/newrelic.ini
COPY docker/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh

# Diretórios de log graváveis pelo www-data (uid/gid 33 no Debian).
RUN set -eux; \
    chmod +x /usr/local/bin/docker-entrypoint.sh; \
    mkdir -p /var/log/php /var/log/newrelic /var/run/newrelic; \
    chown -R www-data:www-data /var/log/php /var/log/newrelic /var/run/newrelic

WORKDIR /var/www/html

EXPOSE 9000

# ─────────────────────────────────────────────────────────────────────────────
# Stage 3: imagem de desenvolvimento (Composer disponível, código via volume)
# ─────────────────────────────────────────────────────────────────────────────
FROM base AS dev

COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1

CMD ["php-fpm"]

# ─────────────────────────────────────────────────────────────────────────────
# Stage 4: imagem de produção (sem Composer, sem ferramentas de dev, não-root)
# ─────────────────────────────────────────────────────────────────────────────
FROM base AS production

LABEL maintainer="oficina-mecanica-api"

COPY --from=vendor /app/vendor ./vendor
COPY src ./src
COPY public ./public
COPY bin ./bin
COPY docs ./docs
COPY swagger.yaml ./swagger.yaml

RUN chown -R www-data:www-data /var/www/html

# Privilégios mínimos: www-data é uid/gid 33 no Debian (era 82 no Alpine).
USER www-data

ENTRYPOINT ["docker-entrypoint.sh"]

CMD ["php-fpm"]
