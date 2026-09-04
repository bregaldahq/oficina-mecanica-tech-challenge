.PHONY: up down install migrate test coverage lint analyse shell \
        build build-dev newrelic-check k8s-render

# ── Desenvolvimento local (docker compose) ───────────────────────────────────
up:
	docker compose up -d --build

down:
	docker compose down

install:
	docker compose exec app composer install

migrate:
	docker compose exec app php bin/migrate.php

test:
	docker compose exec app vendor/bin/phpunit --colors=always

coverage:
	docker compose exec app vendor/bin/phpunit --coverage-html coverage-report/

lint:
	docker compose exec app vendor/bin/php-cs-fixer fix --diff

analyse:
	docker compose exec app vendor/bin/phpstan analyse --no-progress

shell:
	docker compose exec app bash

# ── Imagem ───────────────────────────────────────────────────────────────────
build:
	docker build --target production -t oficina-api:local .

build-dev:
	docker build --target dev -t oficina-api:dev .

# Prova que o agente PHP do New Relic carregou (risco nº 1 do projeto: o agente
# exige glibc, por isso a base é bookworm e não Alpine).
newrelic-check: build
	docker run --rm oficina-api:local php -m | grep -i newrelic

# ── Manifests (kustomize) ────────────────────────────────────────────────────
k8s-render:
	kubectl kustomize deploy/overlays/hml  > /dev/null && echo "hml  ok"
	kubectl kustomize deploy/overlays/prod > /dev/null && echo "prod ok"
