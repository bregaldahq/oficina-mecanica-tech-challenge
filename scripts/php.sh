#!/usr/bin/env bash
# Roda PHP 8.2 em container (a máquina de desenvolvimento não tem PHP instalado).
# Uso: scripts/php.sh vendor/bin/phpunit
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
exec docker run --rm -v "$ROOT":/app -w /app oficina-php:8.2 "$@"
