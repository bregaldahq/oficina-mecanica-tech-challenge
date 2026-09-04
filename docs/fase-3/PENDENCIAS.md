# Pendências — o que depende de você

Todo o desenvolvimento que não exigia credencial, conta ou decisão sua está **pronto e
verificado**. Este documento lista o que sobrou, na ordem em que precisa ser executado.

**Nada aqui foi executado automaticamente de propósito.** Criar repositórios públicos na sua conta
GitHub e provisionar recursos cobrados na AWS são ações externas e de difícil reversão — a decisão
é sua.

Legenda de esforço: 🟢 minutos · 🟡 até 1 hora · 🔴 mais de 1 hora ou espera externa

---

## Bloco 0 · Contas e bootstrap

> Tudo aqui é pré-requisito do primeiro `terraform apply`. O bootstrap do state **não pode** estar
> no Terraform — seria dependência circular.

| # | Ação | Esforço | Feito quando |
|---|---|---|---|
| 0.1 | Criar/confirmar a conta AWS, ativar MFA no root e criar um usuário IAM administrativo | 🟡 | `aws sts get-caller-identity` responde |
| 0.2 | **Criar o AWS Budget com alerta em US$ 20 e outro em US$ 50** — antes de qualquer apply | 🟢 | Budget visível no console, e-mail de teste recebido |
| 0.3 | Criar o bucket de state `oficina-tfstate-<sufixo>` com versionamento e criptografia | 🟢 | `aws s3 ls` mostra o bucket |
| 0.4 | Criar a tabela DynamoDB `oficina-tflock` com chave de partição `LockID` (String) | 🟢 | Tabela `ACTIVE` |
| 0.5 | Criar o OIDC provider do GitHub (`token.actions.githubusercontent.com`) na conta AWS | 🟢 | Provider listado em IAM → Identity providers |
| 0.6 | Criar **4 roles IAM** `oficina-gha-<repo>`, cada uma com trust policy restrita a `repo:bregaldahq/<repo>:*` | 🟡 | As 4 roles existem |
| 0.7 | **Pedir aumento de quota de vCPU on-demand** se a conta for nova | 🔴 espera de horas a 2 dias úteis | Quota aprovada — comece por aqui, é o item de maior latência |
| 0.8 | Criar a conta New Relic (free tier permanente) e obter **account ID**, **license key** e **user API key** | 🟡 | As três chaves em mãos |

> **Comece pelo 0.7.** A aprovação de quota é a única coisa aqui que depende de terceiros e pode
> travar todo o resto por dois dias.

---

## Bloco 1 · Repositórios GitHub

| # | Ação | Esforço | Feito quando |
|---|---|---|---|
| 1.1 | Instalar e autenticar o `gh` CLI (não existe nesta máquina) | 🟢 | `gh auth status` OK |
| 1.2 | Rodar o ensaio: `scripts/bootstrap-repos.sh --dry-run` | 🟢 | Saída revisada, sem surpresa |
| 1.3 | Rodar de verdade: `scripts/bootstrap-repos.sh` | 🟡 | Os 3 repos novos existem, com `main` e `develop` publicadas |
| 1.4 | Criar a branch `develop` também no repositório da aplicação | 🟢 | `develop` publicada |
| 1.5 | Configurar os **secrets** nos 4 repositórios (ver tabela abaixo) | 🟡 | `gh secret list` mostra todos |
| 1.6 | Criar os **environments** `homologacao` e `producao`, com required reviewer em `producao` | 🟢 | Ambos visíveis em Settings → Environments |
| 1.7 | Confirmar o convite de `soat-architecture` nos 4 repositórios | 🟢 | Convite aceito — **tire print de cada um para o PDF** |
| 1.8 | Depois do primeiro pipeline verde, marcar os status checks como obrigatórios na proteção de branch | 🟢 | Checks aparecem como required (só aparecem após rodarem uma vez) |

**Secrets por repositório:**

| Secret | database | k8s | lambda | app |
|---|:-:|:-:|:-:|:-:|
| `AWS_ROLE_ARN` | ✓ | ✓ | ✓ | ✓ |
| `AWS_ACCOUNT_ID` | ✓ | ✓ | ✓ | ✓ |
| `TF_STATE_BUCKET` | ✓ | ✓ | ✓ | — |
| `NEW_RELIC_LICENSE_KEY` | — | ✓ | ✓ | ✓ |
| `NEW_RELIC_ACCOUNT_ID` | ✓ | ✓ | ✓ | ✓ |
| `NEW_RELIC_API_KEY` | ✓ | ✓ | ✓ | ✓ |
| `NEW_RELIC_INFRA_ENTITY_GUID` | — | ✓ | — | — |
| `NEW_RELIC_LAMBDA_ENTITY_GUID` | — | — | ✓ | — |

> Os dois `*_ENTITY_GUID` só podem ser preenchidos **depois do primeiro deploy** — o GUID nasce
> quando o recurso reporta ao New Relic pela primeira vez. As etapas que os usam são
> `continue-on-error`, então o deploy passa com eles vazios. Volte e preencha depois, se quiser o
> marcador de deploy nos gráficos de APM.

---

## Bloco 2 · Provisionamento AWS

> **A ordem importa.** Cada repositório lê do SSM o que o anterior publicou.
> Ordem de `apply`: **database → k8s → lambda → app**. Ordem de `destroy`: **inversa**.

| # | Ação | Esforço | Feito quando |
|---|---|---|---|
| 2.1 | `oficina-infra-database`: apply em `hml` | 🔴 ~15 min de RDS | RDS `available`, 10 parâmetros SSM publicados |
| 2.2 | `oficina-infra-k8s`: apply em `hml` | 🔴 ~15 min de EKS | `kubectl top nodes` responde (prova que o metrics-server subiu e o HPA vai funcionar) |
| 2.3 | Confirmar que os Pods do New Relic apareceram no menu Kubernetes | 🟢 | Cluster visível em até 5 min |
| 2.4 | `oficina-lambda-auth`: **confirmar a versão do layer Bref** e ajustar a variável | 🟢 | Versão conferida em [runtimes.bref.sh](https://runtimes.bref.sh) — está parametrizada, não chute |
| 2.5 | `oficina-lambda-auth`: apply em `hml` | 🟡 | `apigw/endpoint` publicado no SSM |
| 2.6 | Aplicação: merge em `develop` e acompanhar o `deploy.yml` | 🟡 | Rollout completo, smoke test em `/api/ready` verde |
| 2.7 | **Verificar o SG crachá**: `curl` na aplicação tocando o banco, e `POST /auth/cpf` respondendo | 🟢 | Ambos OK — se der **timeout** (não erro de permissão), o `db/client_sg_id` não foi anexado |
| 2.8 | Repetir 2.1–2.6 para `prod` | 🔴 | Ambiente de produção no ar |
| 2.9 | Restringir `cluster_endpoint_public_access_cidrs` em `envs/prod.tfvars` (hoje `0.0.0.0/0`) | 🟢 | CIDR limitado ao seu IP |
| 2.10 | Trocar `capacity_type` para `ON_DEMAND` no ambiente que será avaliado | 🟢 | `envs/prod.tfvars` já está em `ON_DEMAND` — só confirmar |

### Armadilhas conhecidas neste bloco

- **A migration `002` é bloqueante para a aplicação.** O código já espera `customers.status`,
  `email`, `phone` e a tabela `service_order_status_history`. Antes de a `002` rodar,
  `INSERT`/`UPDATE` de cliente e a gravação de histórico **falham**. O Job de migration roda no
  `deploy.yml`, então basta respeitar a ordem — mas se você aplicar a aplicação manualmente,
  rode a migration primeiro.
- **`WEBHOOK_TOKEN` virou obrigatório.** Qualquer ambiente sem esse valor no `oficina-secret`
  passa a responder `401` em `POST /api/service-orders/{id}/approval`. É o comportamento correto
  (antes o endpoint ficava aberto se a variável estivesse vazia), mas é uma quebra.
- **`deletion_protection = true` em prod faz o `destroy` falhar de propósito.** Para destruir, é
  preciso um apply prévio desligando a proteção.
- **`db_multi_az = false`** nos dois ambientes, por custo. As duas subnets privadas em AZs
  distintas já existem — ligar em prod é trocar uma linha em `envs/prod.tfvars`.
- **`max_connections` do `db.t4g.micro`** × 10 réplicas × `pm.max_children` pode estourar
  justamente no pico da demonstração de HPA. Se acontecer, reduza `pm.max_children` ou suba a
  classe da instância antes de gravar.

---

## Bloco 3 · Observabilidade

| # | Ação | Esforço | Feito quando |
|---|---|---|---|
| 3.1 | Substituir `"accountIds": [0]` nos dois JSONs de dashboard pelo seu account ID | 🟢 | Nenhum `[0]` restante |
| 3.2 | Importar os dois dashboards (Dashboards → Import dashboard) | 🟢 | Ambos criados **e com dado** em todos os painéis |
| 3.3 | Criar a política de alertas e as 9 condições de `alertas.json` | 🟡 | 9 condições habilitadas |
| 3.4 | Criar o monitor Synthetic `oficina-prod-health` apontando para `<apigw-endpoint>/api/health` | 🟢 | `ENABLED` em 2 localidades, com execuções bem-sucedidas |
| 3.5 | Criar o destino de e-mail e os workflows de notificação | 🟢 | *Send test notification* recebido |
| 3.6 | Substituir os placeholders `<id da política>` etc. em `politica-notificacao.json` | 🟢 | Arquivo sem `<...>` |

**Nomes que os painéis assumem** — já conferi contra o Terraform e o `deploy/`, e batem:
`appName = 'oficina-api-prod'`, log group `%oficina-prod-api%`, funções `oficina-prod-auth-cpf` e
`oficina-prod-jwt-authorizer`, `containerName = 'php-fpm'`, `hpaName = 'oficina-api'`. Se você
mudar `var.environment` ou o prefixo de nomes, **os painéis ficam vazios sem dar erro**.

---

## Bloco 4 · Entrega

| # | Ação | Esforço | Feito quando |
|---|---|---|---|
| 4.1 | Popular o banco de homologação com o seed `003_seed_demo.sql` (120 OS em 30 dias, 7 status) | 🟢 | Dashboards com dado — **dashboard vazio arruína a demonstração** |
| 4.2 | Subir produção **24–48h antes** da gravação e deixar no ar até a nota sair | 🔴 | Endpoint respondendo (o enunciado pede "links para os deploys ativos") |
| 4.3 | Ensaiar o vídeo cronometrado ao menos uma vez, com plano B da demo de HPA gravado | 🟡 | Ensaio feito, gravação de reserva disponível |
| 4.4 | Gravar seguindo `docs/fase-3/ROTEIRO-VIDEO.md` | 🔴 | Vídeo ≤ 15:00, **sem segredo visível em tela**, áudio audível |
| 4.5 | Publicar no YouTube como **não listado** | 🟢 | Link em mãos |
| 4.6 | Preencher o link do vídeo no `README.md` (seção Entregáveis) | 🟢 | Sem `_adicionar link_` |
| 4.7 | Montar o PDF único: links dos 4 repos, link do vídeo, links das documentações, confirmação do `soat-architecture` | 🟡 | PDF submetido no Portal do Aluno |

---

## Contenção de custo

Com tudo ligado 24/7 são **~US$ 139/mês**. Com o EKS destruído fora das sessões de trabalho, cai
para **~US$ 25/mês**. É exatamente por isso que a VPC e o RDS vivem no repositório de banco e não
no de Kubernetes: você pode rodar `terraform destroy` no repo de K8s ao fim de cada sessão sem
tocar em nada stateful. Recriar o cluster leva ~12 minutos.

Não esqueça o Budget do item 0.2 antes do primeiro apply.

---

## Duas decisões que ficaram em aberto

Nenhuma bloqueia a entrega; ambas são melhorias que registro para você decidir.

1. **Nome do repositório `oficina-infra-database`.** Ele possui bem mais que o banco: a VPC, as
   subnets, os security groups e os segredos de autenticação. Um nome como `oficina-infra-core`
   descreveria melhor. Registrado na ADR-009 como candidato a rename — não renomeei porque o
   enunciado nomeia os quatro repositórios pelo conteúdo esperado.
2. **`parts_inventory.version`** existe no schema para lock otimista, mas a aplicação ainda não o
   usa. Com o HPA subindo até 10 Pods, duas OS concorrentes podem reservar a mesma peça. O
   `CHECK (stock_quantity >= 0)` segura a corrupção de dado, mas a última escrita ainda vence.
   Implementar o lock otimista é trabalho de aplicação, fora do escopo desta fase.
