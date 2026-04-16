# Oficina Mecânica API

[![PHP](https://img.shields.io/badge/PHP-8.2-blue?logo=php)](https://php.net)
[![MySQL](https://img.shields.io/badge/MySQL-8.0-orange?logo=mysql)](https://mysql.com)
[![PHPUnit](https://img.shields.io/badge/Tests-PHPUnit%2011-green)](https://phpunit.de)
[![OpenAPI](https://img.shields.io/badge/Docs-OpenAPI%203.0-brightgreen)](swagger.yaml)
[![Docker](https://img.shields.io/badge/Docker-ready-blue?logo=docker)](Dockerfile)
[![License](https://img.shields.io/badge/License-MIT-yellow)](LICENSE)

Back-end **PHP 8.2 puro** (sem frameworks MVC) para um sistema integrado de atendimento de oficina mecânica. Desenvolvido como **Tech Challenge** da pós-graduação SOAT (Software Architecture) e como portfólio de arquitetura DDD sênior no GitHub.

---

## Sobre o Projeto

O sistema gerencia o ciclo de vida completo de ordens de serviço automotivas: recepção do veículo, diagnóstico, aprovação de orçamento, execução dos serviços, controle de estoque de peças e entrega ao cliente — tudo sem nenhum framework MVC.

### Tecnologias

| Categoria | Tecnologia |
|-----------|-----------|
| Linguagem | PHP 8.2+ (puro, sem Laravel/Symfony) |
| Banco de dados | MySQL 8.0 |
| Autenticação | JWT HS256 implementado em PHP puro |
| Testes | PHPUnit 11 (unitários + integração com SQLite in-memory) |
| Análise estática | PHPStan nível 8 |
| Formatação | PHP-CS-Fixer (PSR-12) |
| Documentação | OpenAPI 3.0 + Swagger UI |
| Containerização | Docker (multi-stage) + Docker Compose + Nginx |

### Por que MySQL?

- **ACID:** Transações garantem que a reserva de estoque e o registro da OS sejam atômicos.
- **Integridade Referencial:** Chaves estrangeiras impedem que uma OS referencie cliente ou veículo inexistente.
- **Relações Complexas:** Modelo com 7 tabelas e relações N:M (OS ↔ Peças, OS ↔ Serviços) naturalmente expressas em SQL.

---

## Arquitetura

```
┌─────────────────────────────────────────────────────────────┐
│                    PRESENTATION LAYER                        │
│   Router → AuthMiddleware → Controllers → RequestValidator   │
└──────────────────────────────┬──────────────────────────────┘
                               │ DTOs
┌──────────────────────────────▼──────────────────────────────┐
│                    APPLICATION LAYER                         │
│               Use Cases + Input/Output DTOs                  │
└──────────────────────────────┬──────────────────────────────┘
                               │ Repository Interfaces
┌──────────────────────────────▼──────────────────────────────┐
│                      DOMAIN LAYER                            │
│  Aggregate Root · Entities · Value Objects · Domain Events   │
│  Repository Interfaces · Domain Exceptions                   │
└──────────────────────────────┬──────────────────────────────┘
                               │ Implementations
┌──────────────────────────────▼──────────────────────────────┐
│                  INFRASTRUCTURE LAYER                        │
│   PDO Repositories · JwtProvider · UuidGenerator            │
│   PdoConnection · EnvLoader · InMemoryEventDispatcher       │
└─────────────────────────────────────────────────────────────┘
```

O domínio não tem nenhuma dependência externa, não conhece PDO, HTTP ou JWT.

### Fluxo da Ordem de Serviço (State Machine)

```
  RECEIVED ──► DIAGNOSIS ──► AWAITING_APPROVAL ──► EXECUTING ──► FINISHED ──► DELIVERED
     │              │                │                   │             │
  recepção     diagnóstico       orçamento           aprovação     execução    entrega
```

Cada transição é um método nomeado no Aggregate Root — nunca um `setStatus()` genérico:

| Método | De | Para |
|--------|----|------|
| `changeToDiagnosis()` | RECEIVED | DIAGNOSIS |
| `sendForApproval()` | DIAGNOSIS | AWAITING_APPROVAL |
| `approve()` | AWAITING_APPROVAL | EXECUTING |
| `finish()` | EXECUTING | FINISHED |
| `deliver()` | FINISHED | DELIVERED |

Transições inválidas lançam `InvalidStatusTransitionException`.

### Modelo de Dados

```
customers ──────────────── vehicles
    │                          │
    └──── service_orders ───────┘
               │
               ├── service_order_services ── service_catalog
               │
               └── service_order_parts ──── parts_inventory
```

---

## Decisões de Design (ADRs)

### ADR-001: PHP puro sem framework MVC
Demonstra domínio profundo de PHP: DI manual, roteamento regex customizado, autoloading PSR-4. O código é completamente auditável — sem "mágica" de framework.

### ADR-002: JWT implementado manualmente
Pure PHP HMAC-SHA256, sem biblioteca externa. Usa `hash_hmac` nativo e `hash_equals` para comparação resistente a timing attacks.

### ADR-003: State Machine com métodos nomeados
`changeToDiagnosis()`, `approve()` etc. capturam a linguagem ubíqua do domínio, encapsulam regras de transição e disparam Domain Events semanticamente corretos.

### ADR-004: Transações no repositório
`PdoServiceOrderRepository::save()` gerencia a transação que persiste a OS, serviços, peças e decrementa o estoque atomicamente. O Use Case não conhece detalhes de persistência.

### ADR-005: `ServiceOrder::reconstitute()` para hidratação
Construtor `private`. O repositório usa `reconstitute()` para rehidratar o agregado sem disparar eventos nem chamar `decreaseStock()` — elimina setters públicos que vazavam responsabilidades de infraestrutura para o domínio.

---

## Linguagem Ubíqua (Glossário)

| Termo | Descrição |
|-------|-----------|
| **Ordem de Serviço (OS)** | Contrato de atendimento entre cliente e oficina |
| **Diagnóstico** | Avaliação técnica do veículo |
| **Orçamento** | Total calculado a partir dos serviços e peças da OS |
| **Aprovação** | Aceite do cliente que libera a execução |
| **Serviço** | Trabalho técnico realizado (ex: Troca de óleo) |
| **Peça** | Item de estoque consumido durante um serviço |
| **Documento** | CPF (11 dígitos) ou CNPJ (14 dígitos) do cliente |
| **Placa** | Identificador único do veículo (formato antigo ou Mercosul) |

---

## Estrutura de Diretórios

```
oficina-mecanica-tech-challenge/
├── src/
│   ├── Domain/
│   │   ├── Aggregate/              # ServiceOrder (Aggregate Root)
│   │   ├── Entity/                 # Customer, Vehicle, Part, ServiceItem
│   │   ├── Event/                  # DomainEventInterface, eventos tipados
│   │   ├── Exception/              # Exceções de domínio tipadas
│   │   ├── Repository/             # Interfaces dos repositórios
│   │   ├── ValueObject/            # Document (CPF/CNPJ), LicensePlate
│   │   └── UuidGeneratorInterface.php
│   ├── Application/
│   │   ├── DTO/                    # Input DTOs por contexto
│   │   └── UseCase/                # Use Cases por contexto
│   ├── Infrastructure/
│   │   ├── Config/                 # EnvLoader
│   │   ├── Database/               # PdoConnection (Singleton + setInstance)
│   │   ├── Event/                  # InMemoryEventDispatcher
│   │   ├── Repository/             # Implementações PDO
│   │   ├── Security/               # JwtProvider (PHP puro, HS256)
│   │   └── UuidGenerator.php       # UUID v4 via random_bytes()
│   └── Presentation/
│       ├── Controller/             # Controllers HTTP
│       ├── Middleware/             # AuthMiddleware (JWT)
│       ├── Request/                # RequestValidator
│       └── Router/                 # Roteador regex com suporte a {param}
├── tests/
│   ├── Unit/
│   │   ├── Application/UseCase/    # Testes de Use Cases com mocks
│   │   ├── Domain/Entity/          # Testes de entidades
│   │   ├── Domain/ValueObject/     # Testes de Document e LicensePlate
│   │   ├── Domain/Part/
│   │   ├── Domain/ServiceOrder/
│   │   ├── Infrastructure/Security/ # JwtProviderTest
│   │   └── ServiceOrderStateTest.php
│   └── Integration/
│       └── PdoServiceOrderRepositoryTest.php  # SQLite in-memory
├── public/
│   └── index.php                   # Front controller
├── docs/
│   └── index.html                  # Swagger UI
├── docker/
│   └── php/php.ini                 # Configuração PHP para produção
├── src/Infrastructure/Database/schema.sql
├── Dockerfile                      # Multi-stage build (vendor → production)
├── docker-compose.yml
├── nginx.conf
├── phpunit.xml
├── phpstan.neon                    # PHPStan nível 8
├── .php-cs-fixer.php               # PSR-12
├── Makefile
├── swagger.yaml
├── SECURITY_REPORT.md
└── IMPLEMENTATION_NOTES.md
```

---

## Pré-requisitos

- Docker 20.10+
- Docker Compose 2.0+

---

## Como Executar

```bash
# 1. Clone o repositório
git clone <repo-url>
cd oficina-mecanica-tech-challenge

# 2. Configure as variáveis de ambiente
cp .env.example .env
# Edite .env — obrigatórios: JWT_SECRET, DB_HOST, DB_DATABASE, DB_USERNAME, DB_PASSWORD, ADMIN_USERNAME, ADMIN_PASSWORD

# 3. Suba os containers (build + start)
make up

# 4. Instale as dependências do Composer dentro do container
make install

# 5. Execute as migrações
make migrate

# 6. Verifique o health check
curl http://localhost:8080/api/health
```

Acesse:
- **API:** `http://localhost:8080/api/`
- **Swagger UI:** `http://localhost:8080/docs/`
- **Health:** `http://localhost:8080/api/health`

### Atalhos com Makefile

```bash
make up        # docker-compose up -d --build
make install   # composer install (dentro do container)
make migrate   # executa schema.sql via migrate.php
make test      # phpunit com cores
make coverage  # relatório HTML de cobertura
make analyse   # PHPStan nível 8
make lint      # PHP-CS-Fixer (fix)
make shell     # acessa o container app
```

---

## Como Testar

```bash
# Testes unitários + integração
docker-compose exec app vendor/bin/phpunit

# Cobertura de código (requer Xdebug no container)
docker-compose exec app vendor/bin/phpunit --coverage-html coverage-report/

# Análise estática
docker-compose exec app vendor/bin/phpstan analyse

# Formatação PSR-12
docker-compose exec app vendor/bin/php-cs-fixer fix --diff
```

A cobertura foca em `src/Domain` e `src/Application` — as camadas com regras de negócio. Testes de integração usam SQLite in-memory via `PdoConnection::setInstance()`.

---

## Endpoints da API

| Método | Endpoint | Auth | Descrição |
|--------|----------|:----:|-----------|
| `GET` | `/api/health` | — | Health check (banco + versão) |
| `POST` | `/api/auth/login` | — | Autenticação, retorna JWT |
| `GET` | `/api/customers` | JWT | Listar clientes |
| `POST` | `/api/customers` | JWT | Criar cliente (CPF ou CNPJ) |
| `GET` | `/api/customers/{id}` | JWT | Buscar cliente |
| `PUT` | `/api/customers/{id}` | JWT | Atualizar nome |
| `DELETE` | `/api/customers/{id}` | JWT | Remover cliente |
| `GET` | `/api/vehicles` | JWT | Listar veículos |
| `POST` | `/api/vehicles` | JWT | Cadastrar veículo |
| `GET` | `/api/vehicles/{id}` | JWT | Buscar veículo |
| `PUT` | `/api/vehicles/{id}` | JWT | Atualizar brand/model/year |
| `DELETE` | `/api/vehicles/{id}` | JWT | Remover veículo |
| `GET` | `/api/parts` | JWT | Listar peças |
| `POST` | `/api/parts` | JWT | Cadastrar peça |
| `GET` | `/api/parts/{id}` | JWT | Buscar peça |
| `PUT` | `/api/parts/{id}` | JWT | Atualizar descrição/preço |
| `PATCH` | `/api/parts/{id}/stock` | JWT | Repor estoque |
| `DELETE` | `/api/parts/{id}` | JWT | Remover peça |
| `GET` | `/api/service-items/metrics` | JWT | Métricas de uso por serviço |
| `GET` | `/api/service-items` | JWT | Listar catálogo de serviços |
| `POST` | `/api/service-items` | JWT | Cadastrar serviço |
| `GET` | `/api/service-items/{id}` | JWT | Buscar serviço |
| `PUT` | `/api/service-items/{id}` | JWT | Atualizar serviço |
| `DELETE` | `/api/service-items/{id}` | JWT | Remover serviço |
| `GET` | `/api/service-orders` | JWT | Listar todas as OS |
| `POST` | `/api/service-orders` | JWT | Criar OS (status: RECEIVED) |
| `GET` | `/api/service-orders/{id}` | JWT | Buscar OS por ID |
| `POST` | `/api/service-orders/{id}/items` | JWT | Adicionar serviços e peças |
| `PATCH` | `/api/service-orders/{id}/status` | JWT | Avançar estado da OS |
| `GET` | `/api/service-orders/status` | — | Consulta pública por CPF+placa |

> A documentação interativa completa (com exemplos de request/response) está disponível em `/docs/`.

---

## Segurança

| Medida | Implementação |
|--------|--------------|
| Autenticação | JWT HS256 com `hash_equals()` (resistente a timing attacks) |
| SQL Injection | PDO com `ATTR_EMULATE_PREPARES = false` (prepared statements nativos) |
| Rate limiting | 5 req/min por IP em `/auth/login` → HTTP 429 + `Retry-After` |
| Headers HTTP | `X-Content-Type-Options`, `X-Frame-Options`, `X-XSS-Protection`, `Referrer-Policy` |
| Env vars | `EnvLoader` valida variáveis obrigatórias na inicialização |
| Erros em prod | `APP_DEBUG=false` oculta stack traces nas respostas |

Análise completa em [SECURITY_REPORT.md](SECURITY_REPORT.md).

---

## Leitura Adicional

- [IMPLEMENTATION_NOTES.md](IMPLEMENTATION_NOTES.md) — decisões técnicas detalhadas e refinamentos aplicados
- [SECURITY_REPORT.md](SECURITY_REPORT.md) — análise de vulnerabilidades e mitigações
- [swagger.yaml](swagger.yaml) — especificação OpenAPI 3.0 completa
