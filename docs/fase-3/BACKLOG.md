# Backlog de execução — Fase 3

Estado vivo do desenvolvimento automatizado. Atualizado a cada iteração do loop de integração.

**Legenda:** `TODO` · `WIP` · `DONE` · `BLOCKED-USER` (depende de ação humana, ver `PENDENCIAS.md`)

**Baseline no início:** PHPUnit 113 testes / 197 asserções verdes, PHPStan nível 8 limpo.

---

## WS-A · `repos/oficina-infra-database`

| ID | Tarefa | Estado |
|---|---|---|
| A1 | `versions.tf`, `providers.tf`, backend S3 parametrizável, `variables.tf`, `envs/{hml,prod}.tfvars` | DONE |
| A2 | VPC 10.20.0.0/16, 2 subnets públicas + 2 privadas em AZs distintas, IGW, route tables, **sem NAT** | DONE |
| A3 | SG `oficina-<env>-db` (ingress 3306 apenas do SG cliente) e SG `oficina-<env>-db-client` | DONE |
| A4 | RDS MySQL 8.0 `db.t4g.micro`, gp3 20GB, encrypted, backup 7d, PI ligado, deletion_protection em prod | DONE |
| A5 | Segredos `oficina/<env>/db` e `oficina/<env>/auth` via `random_password` (formato da seção 3 dos Contratos) | DONE |
| A6 | Outputs → SSM com os nomes exatos da seção 2 dos Contratos | DONE |
| A7 | `migrations/001_initial_schema.sql` (schema atual sem CREATE DATABASE) | DONE |
| A8 | `migrations/002_fase3_ajustes.sql` (os 10 ajustes) | DONE |
| A9 | `migrations/003_seed_demo.sql` — 30 dias de OS sintéticas em vários status, para o dashboard | DONE |
| A10 | `docs/MODELO-DE-DADOS.md`: ER Mermaid, dicionário de dados, relacionamentos, justificativa MySQL × PostgreSQL | DONE |
| A11 | `.github/workflows/{pr,deploy}.yml` no padrão da seção 9 | DONE |
| A12 | `README.md` completo (propósito, tecnologias, execução, deploy, diagrama próprio) | DONE |

## WS-B · `repos/oficina-infra-k8s`

| ID | Tarefa | Estado |
|---|---|---|
| B1 | Esqueleto Terraform + leitura de VPC/subnets/SG do SSM | DONE |
| B2 | EKS 1.30 via `terraform-aws-modules/eks/aws` + managed node group 2× t3.small (min 2 max 4), suporte a Spot por variável | DONE |
| B3 | IRSA + add-on `aws-load-balancer-controller` via Helm | DONE |
| B4 | `metrics-server` via Helm (pré-requisito do HPA) | DONE |
| B5 | `external-secrets` via Helm + IRSA com política de leitura dos 2 segredos + `ClusterSecretStore` | DONE |
| B6 | `nri-bundle` do New Relic (kube-state-metrics + newrelic-logging/Fluent Bit) | DONE |
| B7 | ECR `oficina-api` com lifecycle policy (manter 10 imagens) | DONE |
| B8 | NLB interno + target group + listener :80 para o VPC Link | DONE |
| B9 | Namespaces `oficina-hml` / `oficina-prod`; anexar SG cliente de banco aos nodes | DONE |
| B10 | Outputs → SSM (seção 2) | DONE |
| B11 | Workflows `pr.yml` / `deploy.yml` | DONE |
| B12 | `README.md` completo com diagrama próprio | DONE |

## WS-C · `repos/oficina-lambda-auth`

| ID | Tarefa | Estado |
|---|---|---|
| C1 | `composer.json` com `bref/bref ^2.0`, PSR-4, PHPUnit, PHPStan | DONE |
| C2 | `src/Domain/Cpf.php` — validação de CPF idêntica ao `Document` VO da aplicação | DONE |
| C3 | `src/Security/JwtProvider.php` — **cópia byte a byte** do da aplicação | DONE |
| C4 | `src/Secrets/SecretsProvider.php` — Secrets Manager com cache estático entre invocações | DONE |
| C5 | `src/Handler/AuthCpfHandler.php` — valida, consulta RDS, aplica a tabela de erros da seção 5 | DONE |
| C6 | `src/Handler/JwtAuthorizerHandler.php` — simple response, libera `POST /api/auth/login` | DONE |
| C7 | Testes PHPUnit dos dois handlers (repositório de cliente mockado) | DONE |
| C8 | **Teste de contrato do token** com segredo/iat fixos (seção 4) | DONE |
| C9 | Terraform: 2 Lambdas com layer Bref, VPC config, IAM, log groups | DONE |
| C10 | Terraform: HTTP API, VPC Link, authorizer, rotas, throttling, access log JSON | DONE |
| C11 | Layer da extensão New Relic para Lambda | DONE |
| C12 | Workflows `pr.yml` / `deploy.yml` (build do zip + apply) | DONE |
| C13 | `README.md` completo com diagrama de sequência próprio | DONE |

## WS-D · aplicação (este repositório)

| ID | Tarefa | Estado |
|---|---|---|
| D1 | `Customer`: campos `status`, `email`, `phone` + `CustomerStatus` VO/enum; repositório, controller, DTOs e testes | DONE |
| D2 | `JwtProvider`: injetar segredo por construtor (hoje lê `$_ENV` direto — impede teste de contrato) | DONE |
| D3 | **Teste de contrato do token** espelhando o C8 | DONE |
| D4 | `AuthMiddleware` expõe claims; `Router` ganha `requireRole` | DONE |
| D5 | Aplicar a matriz de autorização da seção 5; **remover** `GET /api/service-orders/status` público | DONE |
| D6 | `GET /api/service-orders/me` — OS do `sub` do token | DONE |
| D7 | `GET /api/service-orders/{id}` — cliente só acessa a própria OS (404 e não 403, para não vazar existência) | DONE |
| D8 | `X-Webhook-Token` obrigatório (hoje libera se a env estiver vazia) | DONE |
| D9 | `JsonLogger` + `CorrelationIdMiddleware` no formato da seção 7 | DONE |
| D10 | Handler global de erro logando `level=error` sem vazar detalhe com `APP_DEBUG=false` | DONE |
| D11 | `StatusHistorySubscriber` grava em `service_order_status_history` + repositório e interface | DONE |
| D12 | `NewRelicSubscriber` emite os 2 custom events (no-op sem a extensão) | DONE |
| D13 | Separar `GET /api/health` (liveness, sem banco) de `GET /api/ready` (readiness, com banco) | DONE |
| D14 | Remover o rate limit em arquivo do `AuthController` (vai para o throttling do gateway) | DONE |
| D15 | `bin/migrate.php` vira runner versionado com `schema_migrations` (seção 6) | DONE |
| D16 | `Dockerfile`: base `bookworm` + agente PHP do New Relic | DONE |
| D17 | `deploy/base` + `deploy/overlays/{hml,prod}` em kustomize; remover `k8s/` e `infra/` | DONE |
| D18 | Probes apontando para `/api/health` e `/api/ready`; HPA max 6 → 10 | DONE |
| D19 | `ExternalSecret` do `oficina-secret` no overlay | DONE |
| D20 | Reescrever `.github/workflows` no padrão da seção 9 (build → ECR → EKS → migration Job → smoke) | DONE |
| D21 | `swagger.yaml`: `/auth/cpf`, `/api/service-orders/me`, `/api/ready`, campos novos de cliente, códigos de erro | DONE |
| D22 | Coleção Postman em `docs/fase-3/postman/` | DONE |
| D23 | Manter PHPUnit e PHPStan nível 8 verdes; cobrir o que foi criado | DONE |

## WS-E · documentação e observabilidade

| ID | Tarefa | Estado |
|---|---|---|
| E1 | ADR-001..005 migradas do README para `docs/fase-3/adr/` no formato Contexto/Decisão/Status/Consequências/Alternativas | DONE |
| E2 | ADR-006 comunicação síncrona REST via API Gateway | DONE |
| E3 | ADR-007 HPA por CPU e memória | DONE |
| E4 | ADR-008 quatro repositórios + acoplamento via SSM | DONE |
| E5 | ADR-009 repositório de banco como camada de fundação (rede, dados, segredos) | DONE |
| E6 | ADR-010 nodes em subnet pública, sem NAT Gateway | DONE |
| E7 | RFC-001 escolha da nuvem | DONE |
| E8 | RFC-002 banco de dados gerenciado | DONE |
| E9 | RFC-003 estratégia de autenticação (incl. limitação HS256 do JWT Authorizer nativo) | DONE |
| E10 | `diagramas/componentes.md` — visão de nuvem | DONE |
| E11 | `diagramas/sequencia-autenticacao.md` | DONE |
| E12 | `diagramas/sequencia-abertura-os.md` | DONE |
| E13 | Dashboards New Relic como JSON em `docs/fase-3/newrelic/` (negócio + plataforma) | DONE |
| E14 | Condições de alerta NRQL em JSON + política de notificação | DONE |
| E15 | `README.md` da aplicação com seção Fase 3, sem `infra/` e `k8s/` | DONE |
| E16 | `docs/fase-3/ROTEIRO-VIDEO.md` minutado | DONE |
| E17 | `PENDENCIAS.md` — tudo que exige ação humana, em ordem de execução | DONE |

## WS-F · integração (feita pelo loop, não por agente)

| ID | Tarefa | Estado |
|---|---|---|
| F1 | Gerar `contract-token.txt` e cravar nos testes de contrato dos dois lados | DONE |
| F2 | `scripts/bootstrap-repos.sh` — cria/publica os 3 repos e aplica branch protection via `gh` | DONE (não executado — ação humana) |
| F3 | Rodar PHPUnit + PHPStan + CS-Fixer e deixar tudo verde | DONE |
| F4 | `terraform fmt -check` e `validate -backend=false` nos 3 stacks | DONE |
| F5 | Conferência final contra a matriz de rastreabilidade do plano | DONE |

---

## Verificação da integração (2026-08-27)

| Checagem | Resultado |
|---|---|
| PHPUnit (aplicação) | **184 testes / 377 asserções** — verde (baseline era 113/197) |
| PHPUnit (lambda) | **71 testes / 124 asserções** — verde |
| PHPStan nível 8 | limpo |
| Teste de contrato do token | idêntico nos dois repositórios, byte a byte |
| `terraform fmt` + `validate` | limpos nos 3 stacks |
| `kubectl kustomize` | overlays `hml` e `prod` renderizam |
| `k8s/` e `infra/` | removidos |

### Rastreabilidade (F5)

Os 20 requisitos do enunciado foram conferidos um a um contra artefato real no disco.
Todos têm implementação. Detalhe em `docs/fase-3/PENDENCIAS.md`.

**Loop de integração encerrado.** O que resta é ação humana: contas, credenciais,
criação dos repositórios no GitHub, provisionamento AWS, importação dos dashboards e gravação
do vídeo.
