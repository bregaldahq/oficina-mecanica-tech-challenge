# WS-A · `repos/oficina-infra-database` — relatório de entrega

**Escopo:** A1–A12 do backlog. Todos os arquivos ficaram contidos em
`repos/oficina-infra-database/`. Nenhum arquivo fora desse diretório foi tocado (exceto este
relatório).

**Verificação executada:**

```
terraform fmt -recursive          → OK
terraform init -backend=false     → OK
terraform validate                → Success! The configuration is valid.
YAML dos 2 workflows              → parse OK
migrations 001+002+003 aplicadas em MySQL 8.0 real (Docker), 2× seguidas → OK
```

---

## 1. O que ficou pronto

| ID | Entrega | Estado |
|---|---|---|
| A1 | `versions.tf`, `providers.tf`, backend S3 parametrizável, `variables.tf`, `envs/{hml,prod}.tfvars` | DONE |
| A2 | VPC 10.20.0.0/16, 2 subnets públicas + 2 privadas em AZs distintas, IGW, route tables, sem NAT | DONE |
| A3 | SG `oficina-<env>-db` + SG `oficina-<env>-db-client` (padrão crachá) | DONE |
| A4 | RDS MySQL 8.0 `db.t4g.micro`, gp3 20GB, encrypted, backup 7d, PI ligado, guard rails por ambiente | DONE |
| A5 | Segredos `oficina/<env>/db` e `oficina/<env>/auth` via `random_password` | DONE |
| A6 | 10 parâmetros SSM com os nomes exatos da seção 2 | DONE |
| A7 | `migrations/001_initial_schema.sql` | DONE |
| A8 | `migrations/002_fase3_ajustes.sql` (os 10 ajustes) | DONE |
| A9 | `migrations/003_seed_demo.sql` (120 OS / 30 dias / 7 status) | DONE |
| A10 | `docs/MODELO-DE-DADOS.md` | DONE |
| A11 | `.github/workflows/{pr,deploy}.yml` | DONE |
| A12 | `README.md` | DONE |

### Confirmação do adendo 3 (rota de egress sem NAT)

Confirmado e presente desde a implementação original de A2:

- `aws_subnet.public` → `map_public_ip_on_launch = true`
- `aws_route_table.public` → `route { cidr_block = "0.0.0.0/0", gateway_id = aws_internet_gateway.main.id }`
- `aws_route_table_association.public` associa as duas subnets públicas a essa route table
- `aws_route_table.private` **não** tem rota `0.0.0.0/0` (só a rota local implícita)

**Não houve correção a fazer.** Foi acrescentado apenas um comentário em `network.tf` marcando a
rota como estrutural e referenciando o adendo, para que ninguém a remova achando que é sobra.

---

## 2. Decisões que tomei e não estavam nos contratos

1. **CIDRs das subnets.** Os contratos fixam só a VPC (`10.20.0.0/16`). Adotei `/20`:
   públicas `10.20.0.0/20` e `10.20.16.0/20`, privadas `10.20.32.0/20` e `10.20.48.0/20`.
   4094 IPs por subnet dão folga para pods do EKS e deixam metade do espaço da VPC livre para
   crescimento.

2. **Egress explícito no SG crachá.** O `db-client` é descrito nos contratos como "vazio". Deixei-o
   sem *ingress*, mas com uma regra de **egress** para o SG do banco na 3306 — sem ela, um cliente
   com o crachá anexado não conseguiria abrir a conexão TCP se o SG for o único anexado à ENI.
   Continua não concedendo acesso *a* nada além do banco.

3. **Tags de subnet para o Kubernetes.** Adicionei `kubernetes.io/role/elb=1` nas públicas e
   `kubernetes.io/role/internal-elb=1` nas privadas. O AWS Load Balancer Controller (WS-B, B3/B8)
   precisa dessas tags para descobrir onde criar o NLB. Sem elas o B8 falharia — mas as subnets
   pertencem a este repo, então a tag tem de ser criada aqui.

4. **`aws_db_parameter_group` próprio.** Não estava no backlog explicitamente (só "parameter group"
   na tabela de propriedade da seção 1). Configurei `character_set_server=utf8mb4`,
   `collation_server=utf8mb4_unicode_ci`, `slow_query_log=1` e `long_query_time=1`.

5. **`from_status`/`to_status` como `VARCHAR(30)`, não `ENUM`.** É o que a seção 6 item 3 manda
   literalmente, e mantive — mas registro o raciocínio no `MODELO-DE-DADOS.md`: uma tabela de
   auditoria precisa continuar legível depois que a máquina de estados evoluir. Note a assimetria
   proposital com `service_orders.status`, que é `ENUM` (item 6).

6. **Storage autoscaling até 100 GB** (`max_allocated_storage`). Não pedido; evita que o banco
   trave por disco cheio em hml sem intervenção.

7. **`recovery_window_in_days` por ambiente:** `0` em hml (permite destroy/apply reutilizando o
   nome do segredo imediatamente) e `7` em prod.

8. **Seed com instante de referência único (`@seed_now`).** Todas as datas do `003` são relativas a
   um único `SET @seed_now = NOW(3);` no topo do arquivo. Com `NOW(3)` avaliado por statement, as
   ~1100 linhas do arquivo eram avaliadas em instantes diferentes e o `created_at` da OS não batia
   com o primeiro registro do histórico (112 de 120 divergiam). Detectado rodando o seed em MySQL
   real, não por inspeção.

9. **Seed sem `UPDATE` de decremento de estoque.** A primeira versão dava baixa no estoque com um
   `UPDATE` ao final — que não é idempotente e zerava o estoque na segunda aplicação.
   `stock_quantity` agora já é o saldo líquido, inserido direto via `INSERT IGNORE`.

10. **Distribuição das 120 OS.** Escolhi 46 `DELIVERED`, 16 `FINISHED`, 15 `EXECUTING`,
    14 `AWAITING_APPROVAL`, 11 `REJECTED`, 10 `DIAGNOSIS`, 8 `RECEIVED` — pipeline vivo no topo do
    funil e massa histórica concluída embaixo, que é o que deixa o dashboard com aparência real.

11. **Job `sql-lint` no `pr.yml`.** Não pedido. Sobe um MySQL 8.0 de serviço, aplica as migrations
    **duas vezes** (prova de idempotência), barra `CREATE DATABASE`/`USE`/`DELIMITER` por grep e
    falha se o seed não cobrir os 7 status.

12. **Marcador de deployment no New Relic.** Este repo não tem entidade APM própria, então o
    marcador é afixado na entidade da aplicação (`oficina-api-<env>`), buscada por `entitySearch`.
    O passo é `continue-on-error` e sai silenciosamente se a entidade ainda não existir — o que é
    o caso no primeiro apply, já que a aplicação é o último repo da ordem.

---

## 3. Divergências encontradas nos contratos

1. **`service_order_status_history` usa `CHAR(36)` enquanto as 7 tabelas originais usam
   `VARCHAR(36)`.** É o que a seção 6 item 3 especifica literalmente, e obedeci. Funciona (a FK é
   aceita porque o charset e a collation coincidem), mas é uma inconsistência de estilo no schema.
   Não é bug; vale uniformizar em uma migration futura, não nesta rodada.

2. **A seção 6 diz "as 7 tabelas atuais" para o `001`, mas o total após o `002` é 8**
   (mais `service_order_status_history`), sem contar `schema_migrations`, criada pelo runner.
   Só uma nota de contagem — nada a corrigir.

3. **Seção 6, item 10 — "`created_at`/`updated_at` → `DATETIME(3)` nas tabelas que os têm".**
   Na prática apenas `customers` (`created_at`) e `service_orders` (`created_at`, `updated_at`)
   possuem essas colunas. As outras cinco tabelas originais não têm timestamps, então o item afeta
   3 colunas em 2 tabelas.

4. **O `002` não é totalmente idempotente no sentido estrito.** Os `ALTER ... MODIFY COLUMN`
   (itens 6, 9 e 10) são reexecutáveis sem erro, e os `ADD COLUMN`/`ADD INDEX`/`ADD CONSTRAINT`
   usam `information_schema` + `PREPARE`/`EXECUTE`. Reaplicar o arquivo inteiro funciona — validei
   rodando 2× — mas emite linhas de resultado `1` dos `SELECT 1` de no-op. É ruído no log, não
   erro. O cabeçalho do arquivo documenta que o controle real é a `schema_migrations`.

---

## 4. O que depende de ação humana

Em ordem de execução:

1. **Criar o bucket S3 do state e a tabela DynamoDB de lock**, fora do Terraform (bootstrap):
   bucket `oficina-tfstate-<sufixo>` com versionamento e criptografia, e tabela `oficina-tflock`
   com chave de partição `LockID` (String). Nada neste repo os cria — seria dependência circular.

2. **Escolher o `<sufixo>` do bucket** e registrá-lo no secret `TF_STATE_BUCKET` do repositório.

3. **Criar o OIDC provider do GitHub na conta AWS** (`token.actions.githubusercontent.com`) e o
   role `oficina-gha-oficina-infra-database`, com trust policy restrita a este repositório e às
   branches `develop`/`main`. Não está no Terraform deste repo — é pré-requisito do próprio CI.

4. **Popular os secrets do repositório GitHub:** `AWS_ROLE_ARN`, `AWS_ACCOUNT_ID`,
   `TF_STATE_BUCKET`, `NEW_RELIC_API_KEY`, `NEW_RELIC_ACCOUNT_ID`.

5. **Criar os environments `homologacao` e `producao`** no GitHub, com required reviewer em
   `producao` (o `deploy.yml` já referencia os dois pelo nome).

6. **Confirmar o limite de Elastic IP / VPC por região** — o stack cria 1 VPC por ambiente; com
   `hml` e `prod` na mesma conta e região, são 2 VPCs, dentro do default de 5.

7. **Decisão consciente antes de qualquer `destroy` em prod:** `deletion_protection = true` faz o
   destroy do RDS falhar por projeto. É preciso um apply prévio desligando a proteção.

8. **Revisar a dívida de Multi-AZ.** `db_multi_az = false` nos dois ambientes, por custo. Ligar em
   prod é trocar uma linha em `envs/prod.tfvars` — as duas subnets privadas em AZs distintas já
   existem para isso.

9. **WS-B precisa anexar o SG crachá aos nodes** (`/oficina/<env>/db/client_sg_id`) e **WS-C
   precisa anexá-lo às Lambdas**. Sem isso ninguém alcança o banco, e o sintoma é timeout de
   conexão, não erro de permissão. Vale um smoke test explícito do lado deles.
