#!/bin/sh
# Materializes the .env file the application expects (EnvLoader::loadFile) from the
# container environment (Kubernetes ConfigMap + Secret) when it is not already present.
# In local development the .env file is bind-mounted, so this stays a no-op there.
set -e

ENV_FILE="/var/www/html/.env"

if [ ! -f "$ENV_FILE" ]; then
    for var in APP_ENV APP_DEBUG \
               DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD \
               JWT_SECRET JWT_EXPIRATION \
               ADMIN_USERNAME ADMIN_PASSWORD \
               WEBHOOK_TOKEN \
               SMTP_HOST SMTP_PORT SMTP_USERNAME SMTP_PASSWORD MAIL_FROM MAIL_TO; do
        eval "value=\${$var:-}"
        if [ -n "$value" ]; then
            printf '%s=%s\n' "$var" "$value" >> "$ENV_FILE"
        fi
    done
fi

exec "$@"
