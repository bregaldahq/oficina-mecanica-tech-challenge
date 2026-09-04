# WS-D1 — Código da aplicação (D1–D15, D21, D23)

Workstream do repositório `oficina-mecanica-tech-challenge`, escopo de arquivos:
`src/`, `tests/`, `bin/`, `public/`, `swagger.yaml`.

**Resultado:** todos os IDs do escopo entregues. Suíte de 113 → **184 testes / 377 asserções**,
verdes; PHPStan nível 8 limpo.

---

## 1. Pronto por ID

| ID | Entrega | Estado |
|---|---|---|
| D1 | `CustomerStatus` (enum PHP 8.1 em `src/Domain/ValueObject/`), `Customer` com `status`/`email`/`phone`, `PdoCustomerRepository`, `CustomerController`, `toArray()`, schema do teste de integração | DONE |
| D2 | `JwtProvider` com segredo, expiração e **relógio** injetados por construtor + `JwtProvider::fromEnv()` para o composition root | DONE |
| D3 | `tests/Unit/Infrastructure/Security/JwtContractTest.php` — reproduz o token literal de `contract-token.md` | DONE |
| D4 | `AuthMiddleware` publica as claims (retorno + `RequestContext`); `Router` ganha `requireRole()` e repassa as claims ao handler | DONE |
| D5 | Matriz da seção 5 aplicada em `public/index.php`; `GET /api/service-orders/status` **removida** (rota e use case) | DONE |
| D6 | `GET /api/service-orders/me` via `sub` do token (`ListServiceOrdersByCustomerUseCase` + `findByCustomerId`) | DONE |
| D7 | Cliente consultando OS de outro recebe **404** com a mesma mensagem de "não encontrada" | DONE |
| D8 | `X-Webhook-Token` obrigatório: `WEBHOOK_TOKEN` vazio agora **fecha** o endpoint | DONE |
| D9 | `JsonLogger` + `CorrelationIdMiddleware` no formato exato da seção 7 | DONE |
| D10 | Handler global instrumentado: `request.completed` sempre, `request.failed` em `level=error` sem vazar detalhe com `APP_DEBUG=false` | DONE |
| D11 | `StatusHistorySubscriber` + `ServiceOrderStatusHistoryRepositoryInterface` + implementação PDO, registrados no `InMemoryEventDispatcher` | DONE |
| D12 | `NewRelicSubscriber` com os 2 custom events e no-op silencioso sem a extensão | DONE |
| D13 | `GET /api/health` (liveness, sem banco) separado de `GET /api/ready` (readiness, com `SELECT 1`) | DONE |
| D14 | `checkRateLimit()` removido do `AuthController` (vai para o throttling do API Gateway) | DONE |
| D15 | `bin/migrate.php` → `MigrationRunner` versionado com `schema_migrations`, `MIGRATIONS_PATH`, ordem lexicográfica, skip de `*_demo.sql` em produção, idempotente | DONE |
| D21 | `swagger.yaml` com `/auth/cpf`, `/api/service-orders/me`, `/api/ready`, campos novos de cliente, matriz de autorização e códigos de erro da seção 5 | DONE |
| D23 | 71 testes novos cobrindo tudo que foi criado; suíte e PHPStan verdes | DONE |

---

## 2. Arquivos criados

```
src/Domain/ValueObject/CustomerStatus.php
src/Domain/Repository/ServiceOrderStatusHistoryRepositoryInterface.php
src/Application/UseCase/ServiceOrder/ListServiceOrdersByCustomerUseCase.php
src/Infrastructure/Context/RequestContext.php
src/Infrastructure/Logging/JsonLogger.php
src/Infrastructure/Database/MigrationRunner.php
src/Infrastructure/Repository/PdoServiceOrderStatusHistoryRepository.php
src/Infrastructure/Event/Subscriber/StatusHistorySubscriber.php
src/Infrastructure/Event/Subscriber/NewRelicSubscriber.php
src/Presentation/Middleware/CorrelationIdMiddleware.php
tests/Unit/Infrastructure/Security/JwtContractTest.php
tests/Unit/Infrastructure/Logging/JsonLoggerTest.php
tests/Unit/Infrastructure/Database/MigrationRunnerTest.php
tests/Unit/Infrastructure/Event/NewRelicSubscriberTest.php
tests/Unit/Infrastructure/Event/StatusHistorySubscriberTest.php
tests/Unit/Presentation/Router/RouterTest.php
tests/Unit/Presentation/Middleware/CorrelationIdMiddlewareTest.php
tests/Unit/Presentation/Controller/HealthControllerTest.php
tests/Unit/Presentation/Controller/ServiceOrderControllerTest.php
tests/Unit/Application/UseCase/ListServiceOrdersByCustomerUseCaseTest.php
tests/Unit/Domain/ValueObject/CustomerStatusTest.php
```

Removido: `src/Application/UseCase/ServiceOrder/GetServiceOrderByClientUseCase.php`
(alimentava apenas a rota pública que vazava OS por CPF).

---

## 3. Decisões fora dos contratos

Nenhuma contraria os Contratos; são escolhas de implementação que eles não especificam.

1. **Relógio injetável no `JwtProvider`.** O contrato exige `iat` fixo no teste de contrato mas
   não diz como. Optei por um terceiro parâmetro `?\Closure(): int $clock` (default `time()`)
   em vez de expor a montagem de claims — mantém `generate()` como única porta de saída e deixa
   a ordem das claims impossível de burlar por acidente. `fromEnv()` preserva o composition root.

2. **`RequestContext`** (`src/Infrastructure/Context/`). Os subscribers precisam de
   `correlationId` e do ator (`sub`) para os custom events e para `changed_by`, e não têm como
   alcançá-los. Criei um objeto de contexto por request, instanciado no composition root e
   injetado — sem estado global/estático. `AuthMiddleware` o alimenta ao validar o token.

3. **`findLastChangedAtBefore($orderId, $before)`** em vez de "última transição". Se o método
   fosse "a última", o `durationSeconds` do `NewRelicSubscriber` dependeria de o
   `StatusHistorySubscriber` já ter gravado ou não a linha da transição corrente — ou seja, da
   ordem de registro dos subscribers. Com o corte por `before`, o resultado é o mesmo em
   qualquer ordem.

4. **`ServiceOrderCreated` grava histórico `null → RECEIVED`.** A criação é a primeira
   transição; sem essa linha a primeira mudança de status ficaria sem `durationSeconds`.

5. **`MigrationRunner` separado de `bin/migrate.php`.** O script virou casca fina (conexão +
   env) e a lógica foi para `src/Infrastructure/Database/MigrationRunner.php`, testável com
   SQLite. O runner detecta o driver para criar `schema_migrations` (MySQL usa `DATETIME(3)` e
   `ENGINE=InnoDB`, SQLite não entende nenhum dos dois). O DDL em MySQL é a forma exata da
   seção 6.

6. **`Router` fluente.** `requireRole()` age sobre a última rota registrada
   (`$router->get(...)->requireRole('admin')`), o que deixa a matriz da seção 5 legível linha a
   linha no `index.php`. Chamar `requireRole()` sem rota anterior lança `LogicException`.

7. **Normalização de contato no `Customer`.** `email` é validado com `FILTER_VALIDATE_EMAIL` e
   `phone` é reduzido a dígitos (10–13). Os contratos só definem os tipos das colunas; a
   validação é decisão local, e `''` vira `null` para não gravar string vazia.

8. **Log de request emitido também via `register_shutdown_function`.** `AuthMiddleware` e
   `RequestValidator` encerram com `exit`; sem o shutdown, requests 401/400 sairiam sem linha
   de log. Há guarda contra linha duplicada.

9. **Ordem de rotas.** `GET /api/service-orders/me` é registrada **antes** de
   `/api/service-orders/{id}`, senão `me` seria capturada como um id.

---

## 4. Divergências encontradas nos contratos

1. **`contract-token.txt` × `contract-token.md`.** A seção 4 dos Contratos diz que o token
   esperado está em `docs/fase-3/contract-token.txt`; o arquivo existente é
   `docs/fase-3/contract-token.md`. Usei o `.md`. O token bate byte a byte.

2. **`GET /api/service-orders/{id}` para `customer` e o API Gateway.** A matriz dá acesso ao
   "`customer` dono da OS", mas o `jwt-authorizer` libera qualquer token válido em
   `ANY /api/{proxy+}` — a checagem de posse é necessariamente da aplicação (é o que está
   implementado). Não é conflito, mas convém não presumir defesa no gateway.

3. **`POST /auth/cpf` fora do prefixo `/api`.** Os `servers` do `swagger.yaml` já embutem
   `/api`, e essa rota vive na raiz do API Gateway. Documentei a rota como `/auth/cpf` com nota
   explícita de que, nos servidores declarados, o caminho real é `{apigw}/auth/cpf`. Alternativa
   seria quebrar os `servers` em dois documentos — não me pareceu valer o custo.

4. **Formato do `document` no `Customer`.** O contrato do `/auth/cpf` aceita só CPF, mas a
   entidade `Customer` da aplicação continua aceitando CNPJ (cadastro de PJ pelo admin). Mantido
   como está; a restrição a CPF é da Lambda.

---

## 5. Depende de ação humana

1. **Migrations SQL (`001`, `002`, `003_seed_demo`)** são do repo `oficina-infra-database`
   (WS-A). O runner está pronto e testado, mas **nada foi aplicado**: sem os `.sql`, as colunas
   `customers.status/email/phone` e a tabela `service_order_status_history` não existem no
   banco. A hidratação de `Customer` foi escrita tolerante a colunas ausentes, mas
   `INSERT`/`UPDATE` de cliente e a gravação de histórico **falham** até a `002` rodar.

2. **`WEBHOOK_TOKEN` passou a ser obrigatório.** Qualquer ambiente (inclusive local) sem esse
   valor no `oficina-secret` responde `401` em `POST /api/service-orders/{id}/approval`. É o
   comportamento pedido, mas é uma quebra em relação ao ambiente atual.

3. **Probes do Kubernetes** precisam apontar `livenessProbe → /api/health` e
   `readinessProbe → /api/ready` (D18, fora do meu escopo). Enquanto o liveness apontar para a
   rota que checa banco, o ganho do D13 não se materializa.

4. **Extensão New Relic** não existe local nem em CI — os custom events são no-op silencioso.
   A emissão real só pode ser validada com o agente instalado na imagem (D16).

5. **Throttling do login** foi removido da aplicação e **ainda não existe** no gateway até o
   WS-C aplicar o estágio com throttling. Há uma janela em que `POST /api/auth/login` fica sem
   qualquer limite.

6. **Coleção Postman (D22)** e os itens D16–D20 não são deste escopo.

---

## 6. Saída final

```
$ ./scripts/php.sh vendor/bin/phpunit --no-coverage
PHPUnit 11.5.55 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.2.32
Configuration: /app/phpunit.xml

...............................................................  63 / 184 ( 34%)
............................................................Failed to send status-change notification: smtp unavailable
... 126 / 184 ( 68%)
..........................................................      184 / 184 (100%)

Time: 00:00.071, Memory: 12.00 MB

OK (184 tests, 377 assertions)
```

> A linha `Failed to send status-change notification: smtp unavailable` é saída esperada do
> teste do notificador SMTP, já presente na baseline.

```
$ ./scripts/php.sh vendor/bin/phpstan analyse --no-progress --memory-limit=512M
Note: Using configuration file /app/phpstan.neon.

 [OK] No errors
```
