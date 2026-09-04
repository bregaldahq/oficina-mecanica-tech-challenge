# Observabilidade no New Relic — como importar

Quatro arquivos, dois caminhos de importação (interface e NerdGraph). Tudo aqui parte dos
contratos da **seção 7** de [`../CONTRATOS.md`](../CONTRATOS.md): `appName` = `oficina-api-<env>`,
custom events `ServiceOrderCreated` e `ServiceOrderStatusChanged`, e log estruturado em JSON com
`service`, `env`, `correlation_id`, `level`.

| Arquivo | O que é | Como importar |
|---|---|---|
| `dashboard-negocio.json` | Dashboard de negócio: volume diário de OS, tempo médio por status, funil de status, ticket médio | Interface — *Import dashboard* |
| `dashboard-plataforma.json` | Dashboard técnico em 3 páginas: API, Cluster/HPA, Lambdas/gateway | Interface — *Import dashboard* |
| `alertas.json` | Política, 9 condições de alerta NRQL e o monitor Synthetic de `/api/health` | NerdGraph (ou recriar pela interface) |
| `politica-notificacao.json` | Destinos, canal de e-mail e workflows de notificação | NerdGraph (ou recriar pela interface) |

---

## Pré-requisitos

1. Conta New Relic (o free tier de 100 GB/mês é suficiente para este projeto).
2. **Account ID** — canto superior direito, em *Administration → Access management*.
3. **User API key** (`NRAK-...`) para o NerdGraph — *API keys → Create a key → User*.
4. **License key** (`...NRAL`) para o agente — vai nos secrets de repositório
   (`NEW_RELIC_LICENSE_KEY`) e é consumida pelo `nri-bundle` e pelo agente PHP.
5. Telemetria já chegando: a aplicação com o agente PHP (`NEW_RELIC_APP_NAME=oficina-api-prod`),
   o `nri-bundle` instalado pelo `oficina-infra-k8s` e a layer do New Relic nas Lambdas.

> **Importe os dashboards só depois que houver dado.** Painéis vazios não confirmam nada — e o
> erro mais comum (nome de evento ou de atributo diferente do contrato) só aparece com dado real.

---

## 1. Dashboards

### Passo a passo

1. Abra o arquivo e **substitua todas as ocorrências de `"accountIds": [0]`** pelo seu account ID.
   ```bash
   sed -i 's/"accountIds": \[0\]/"accountIds": [SEU_ACCOUNT_ID]/g' dashboard-negocio.json
   sed -i 's/"accountIds": \[0\]/"accountIds": [SEU_ACCOUNT_ID]/g' dashboard-plataforma.json
   ```
2. Para visualizar **homologação** em vez de produção, troque também o ambiente:
   ```bash
   sed -i "s/env = 'prod'/env = 'hml'/g; s/oficina-api-prod/oficina-api-hml/g; s/oficina-prod/oficina-hml/g" dashboard-negocio.json
   ```
3. No New Relic: **Dashboards → Import dashboard** (botão `+`, canto superior direito), cole o
   JSON inteiro e confirme.
4. Ajuste o intervalo de tempo no topo do dashboard — as consultas trazem `SINCE` próprio, mas o
   seletor global prevalece em alguns widgets.

### Validação

Após importar, cada painel deve mostrar dado — nunca *"No chart data"*. Se algum ficar vazio,
rode a consulta isolada em **Query your data** e confirme o nome do evento e do atributo. Erro em
apenas um painel é problema de atributo; erro em todos os painéis de uma página é problema de
ingestão (agente não instalado, `appName` divergente, integração de Kubernetes ausente).

### Dependências por página

| Página | Depende de |
|---|---|
| Negócio | `NewRelicSubscriber` da aplicação emitindo os custom events (WS-D12) |
| Plataforma → API | Agente PHP do New Relic na imagem (WS-D16) + Fluent Bit do `nri-bundle` |
| Plataforma → Cluster e HPA | `nri-bundle` com `kube-state-metrics` (WS-B6) |
| Plataforma → Lambdas e gateway | Layer do New Relic nas Lambdas (WS-C11) + integração AWS habilitada |

---

## 2. Alertas (`alertas.json`)

O formato é neutro e legível — descreve política, condições e monitor Synthetic. Não é um payload
de importação de um clique: alertas no New Relic são criados por **NerdGraph** ou pela interface.

### Opção A — interface (mais rápido para poucos alertas)

1. **Alerts → Alert policies → New alert policy**, com o `name` e o `incidentPreference` do bloco
   `policy`.
2. Para cada item de `conditions`: **Create condition → NRQL**, cole o `nrql.query`, e preencha os
   campos a partir de `signal` (janela de agregação, método, atraso, preenchimento) e `terms`
   (prioridade, operador, limiar, duração).
3. Copie o `description` de cada condição para o campo de descrição — ele contém o **runbook**,
   que é o que transforma um alerta em algo acionável às 3 da manhã.

### Opção B — NerdGraph (reprodutível)

```bash
export NR_API_KEY='NRAK-...'
export NR_ACCOUNT_ID='1234567'

# 1. criar a política
curl -s https://api.newrelic.com/graphql \
  -H "Api-Key: $NR_API_KEY" -H 'Content-Type: application/json' \
  -d '{"query":"mutation { alertsPolicyCreate(accountId: '"$NR_ACCOUNT_ID"', policy: {name: \"Oficina Mecânica · prod\", incidentPreference: PER_CONDITION_AND_TARGET}) { id } }"}'
```

Guarde o `id` retornado e crie cada condição com
`alertsNrqlConditionStaticCreate(accountId:, policyId:, condition: {...})`, mapeando:

| Campo do JSON | Campo do NerdGraph |
|---|---|
| `nrql.query` | `nrql: { query: "..." }` |
| `signal.aggregationWindow` | `signal: { aggregationWindow: N }` |
| `signal.aggregationMethod` | `signal: { aggregationMethod: EVENT_FLOW }` |
| `terms[]` | `terms: [{ priority: CRITICAL, operator: ABOVE, threshold: N, thresholdDuration: N, thresholdOccurrences: ALL }]` |
| `violationTimeLimitSeconds` | `violationTimeLimitSeconds` |

> `signal.fillOption: STATIC` com `fillValue: 0` é essencial nas condições que **contam** eventos
> (erros, falhas de synthetic). Sem ele, ausência de dado não é lida como zero e o incidente nunca
> fecha sozinho.

### Monitor Synthetic

Crie separadamente em **Synthetic monitoring → Create monitor → Availability (ping)**, com os
valores do bloco `syntheticMonitor`. Substitua `<apigw-endpoint>` pelo valor de
`/oficina/prod/apigw/endpoint`:

```bash
aws ssm get-parameter --name /oficina/prod/apigw/endpoint --query Parameter.Value --output text
```

Duas localidades (`AWS_US_EAST_1` e `AWS_SA_EAST_1`) evitam falso positivo por problema de rede em
uma região. O nome do monitor precisa ser exatamente **`oficina-prod-health`**, porque a condição
`Synthetic · /api/health indisponível` filtra por `monitorName`.

### Condições entregues

| Condição | Limiar crítico | Cobre |
|---|---|---|
| OS · falha no processamento | > 3 erros em 5 min | Falha de negócio nas rotas de OS |
| API · latência p95 | > 1500 ms por 5 min | SLI de latência |
| Synthetic · `/api/health` | ≥ 2 falhas em 5 min | Disponibilidade externa |
| Cluster · saturação de CPU | > 85% do request por 5 min | HPA não dando conta |
| Cluster · HPA no teto | `current == max` por 10 min | Esgotamento de capacidade |
| Cluster · Pods reiniciando | > 2 em 10 min | OOMKilled / probe falhando |
| Lambda `auth-cpf` · erro | > 1% por 5 min | Cliente não consegue token |
| Lambda `jwt-authorizer` · erro | > 1% por 5 min | Todas as rotas `/api/**` caem |
| API · erro 5xx | > 5% por 5 min | Erro sistêmico |

---

## 3. Notificação (`politica-notificacao.json`)

1. **Alerts → Destinations → Add destination → Email**, com o seu e-mail. Anote o ID gerado.
2. **Alerts → Workflows → Add workflow**: filtre por `policyIds` (a política criada no passo 2) e
   por `priority`, e ligue o canal criado.
3. Substitua no arquivo os placeholders `<id do destino de e-mail>`, `<id do canal de e-mail>` e
   `<id da política>` pelos IDs reais, para que o arquivo continue servindo de documentação do que
   foi feito.
4. **Teste antes de confiar:** o botão *Send test notification* do destino. Um alerta que não
   chega é pior do que não ter alerta.

O destino de Slack é **opcional** — crie apenas se houver workspace disponível. O e-mail é o canal
mínimo.

A regra em `mutingRules` nasce **desativada**: silenciar alertas de cluster durante um deploy é
útil, mas ligá-la e esquecer de desligar é uma forma clássica de perder incidente. O `deploy.yml`
já marca o deployment no New Relic (seção 9 dos Contratos), o que permite correlacionar
visualmente sem precisar silenciar nada.

---

## Divergências conhecidas em relação aos Contratos

| Item | Situação |
|---|---|
| **`totalAmount` no `ServiceOrderStatusChanged`** | Os painéis de **ticket médio** e **faturamento** consultam `average(totalAmount)`, mas a seção 7 dos Contratos **não lista** esse atributo. Sem ele, esses dois painéis ficam vazios. Requer adendo ao contrato e ajuste no `NewRelicSubscriber` (WS-D12): incluir `totalAmount` no evento, ao menos nas transições para `FINISHED` e `DELIVERED`. |
| **`durationSeconds` em `ServiceOrderCreated`** | Não existe, e está correto: `RECEIVED` é o primeiro estado, não há transição anterior. O painel de tempo por status filtra `fromStatus IS NOT NULL` por causa disso. |
| **Nomes das entidades de Lambda** | As consultas assumem `entityName` = `oficina-prod-auth-cpf` e `oficina-prod-jwt-authorizer`. Confirmar contra o Terraform do `oficina-lambda-auth` (WS-C9) e ajustar se o padrão de nome divergir. |
| **`containerName = 'php-fpm'`** | Assumido a partir do desenho do Pod (Nginx + PHP-FPM). Confirmar contra `deploy/base` (WS-D17). |
| **Campos do access log do gateway** | As consultas de `Log` usam `status` e `routeKey`, que dependem do formato do access log JSON definido no WS-C10. |
