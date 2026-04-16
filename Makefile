.PHONY: up down install migrate test coverage lint analyse shell

up:
	docker-compose up -d --build

down:
	docker-compose down

install:
	docker-compose exec app composer install

migrate:
	docker-compose exec app php bin/migrate.php

test:
	docker-compose exec app vendor/bin/phpunit --colors=always

coverage:
	docker-compose exec app vendor/bin/phpunit --coverage-html coverage-report/

lint:
	docker-compose exec app vendor/bin/php-cs-fixer fix --diff

analyse:
	docker-compose exec app vendor/bin/phpstan analyse --no-progress

shell:
	docker-compose exec app sh
