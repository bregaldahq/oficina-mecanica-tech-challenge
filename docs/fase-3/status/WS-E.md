# WS-E · Documentação arquitetural e observabilidade — relatório

**Workstream:** documentação e observabilidade (E1–E17)
**Escopo de arquivos:** `docs/fase-3/{adr,rfc,diagramas,newrelic}/`, `docs/fase-3/ROTEIRO-VIDEO.md`,
`README.md`, `docs/DOMAIN-STORYTELLING.md`.

---

## Estado por ID

| ID | Entrega | Estado |
|---|---|---|
| E1 | ADR-001 a 005 migradas do README para o formato completo | **DONE** |
| E2 | ADR-006 · comunicação síncrona REST via API Gateway | **DONE** |
| E3 | ADR-007 · HPA por CPU e memória (70% / 80%, 2 a 10) | **DONE** |
| E4 | ADR-008 · quatro repositórios + acoplamento por SSM | **DONE** |
| E5 | ADR-009 · repositório de banco como camada de fundação | **DONE** |
| E6 | ADR-010 · nodes em subnet pública, sem NAT Gateway | **DONE** |
| E7 | RFC-001 · escolha da nuvem (AWS × GCP × Azure) | **DONE** |
| E8 | RFC-002 · banco gerenciado (RDS MySQL × Aurora v2 × RDS PG × DynamoDB) | **DONE** |
| E9 | RFC-003 · estratégia de autenticação (Lambda+HS256 × Cognito × mTLS) | **DONE** |
| E10 | `diagramas/componentes.md` — visão de nuvem | **DONE** |
| E11 | `diagramas/sequencia-autenticacao.md` | **DONE** |
| E12 | `diagramas/sequencia-abertura-os.md` | **DONE** |
| E13 | Dashboards New Relic (negócio + plataforma) | **DONE** |
| E14 | Condições de alerta NRQL + política de notificação | **DONE** |
| E15 | `README.md` reescrito com a seção Fase 3 | **DONE** |
| E16 | `ROTEIRO-VIDEO.md` minutado | **DONE** |
| E17 | `PENDENCIAS.md` | **NÃO FEITO** — por instrução do orquestrador; consolidado na integração final |

**Extras entregues** (não estavam no backlog, mas fazem falta):

- `docs/fase-3/adr/README.md` — índice das ADRs, grafo de relação entre decisões e ligação com as
  RFCs.
- `docs/fase-3/diagramas/README.md` — índice dos diagramas.
- `docs/fase-3/newrelic/README.md` — instruções de importação de cada arquivo (exigido no escopo).
- `docs/DOMAIN-STORYTELLING.md` atualizado: a História 1 tinha um passo — "o Cliente consulta o
  status pela rota pública" — que descrevia justamente a falha de controle de acesso removida
  nesta fase. Ficaria incoerente com o código.

---

## Arquivos criados ou alterados

```
docs/fase-3/adr/README.md                                     (novo)
docs/fase-3/adr/001-php-puro-sem-framework.md                 (novo)
docs/fase-3/adr/002-jwt-implementado-manualmente.md           (novo)
docs/fase-3/adr/003-state-machine-metodos-nomeados.md         (novo)
docs/fase-3/adr/004-transacoes-no-repositorio.md              (novo)
docs/fase-3/adr/005-reconstitute-para-hidratacao.md           (novo)
docs/fase-3/adr/006-comunicacao-sincrona-rest-api-gateway.md  (novo)
docs/fase-3/adr/007-hpa-cpu-memoria.md                        (novo)
docs/fase-3/adr/008-quatro-repositorios-acoplamento-ssm.md    (novo)
docs/fase-3/adr/009-banco-como-camada-de-fundacao.md          (novo)
docs/fase-3/adr/010-nodes-em-subnet-publica-sem-nat.md        (novo)
docs/fase-3/rfc/001-escolha-da-nuvem.md                       (novo)
docs/fase-3/rfc/002-banco-gerenciado.md                       (novo)
docs/fase-3/rfc/003-estrategia-de-autenticacao.md             (novo)
docs/fase-3/diagramas/README.md                               (novo)
docs/fase-3/diagramas/componentes.md                          (novo)
docs/fase-3/diagramas/sequencia-autenticacao.md               (novo)
docs/fase-3/diagramas/sequencia-abertura-os.md                (novo)
docs/fase-3/newrelic/README.md                                (novo)
docs/fase-3/newrelic/dashboard-negocio.json                   (novo)
docs/fase-3/newrelic/dashboard-plataforma.json                (novo)
docs/fase-3/newrelic/alertas.json                             (novo)
docs/fase-3/newrelic/politica-notificacao.json                (novo)
docs/fase-3/ROTEIRO-VIDEO.md                                  (novo)
docs/fase-3/status/WS-E.md                                    (novo)
README.md                                                     (reescrito em grande parte)
docs/DOMAIN-STORYTELLING.md                                   (atualizado)
```

Os três JSONs de New Relic foram validados com `json.load` — sintaxe correta.

Nenhum arquivo fora do escopo foi tocado: `src/`, `tests/`, `deploy/`, `.github/`, `Dockerfile`,
`repos/`, `swagger.yaml`, `CONTRATOS.md`, `BACKLOG.md` e `contract-token.md` permanecem
intactos.

---

## Decisões tomadas nesta workstream

1. **Uma decisão por arquivo de ADR, formato fixo.** As cinco ADRs herdadas eram parágrafos de uma
   frase no README; foram expandidas com contexto real, consequências **negativas** explícitas e
   alternativas com justificativa de rejeição. As três primeiras foram reavaliadas à luz da Fase 3
   (a ADR-002, em particular, mudou de status: o `JwtProvider` virou artefato de contrato entre
   dois repositórios).

2. **O README passou a linkar as ADRs em vez de contê-las.** Documentação de decisão pertence a um
   lugar onde possa crescer e ser superseded; um README que carrega decisões inline acaba
   desatualizado.

3. **Consequências negativas foram escritas sem suavização** — em especial na ADR-010, que tem uma
   seção própria chamada "O que se perde — sendo honesto". Essa é a ADR com maior probabilidade de
   ser questionada pela banca, e a defesa mais forte é ter antecipado a crítica. O mesmo vale para
   a nota sobre CPF não ser autenticação forte, na RFC-003.

4. **A limitação do JWT Authorizer nativo virou seção própria na RFC-003**, com a explicação de
   *por que* HS256 não pode ser validado por um authorizer que obtém chaves de um JWKS público —
   e não apenas a constatação de que "não funciona". O plano de evolução para RS256 tem sete
   passos, cada um reversível, com o bloqueador (domínio próprio + ACM) identificado no passo 3.

5. **Diagramas em Mermaid versionado**, não imagem. Revisável em PR e sem ferramenta externa.

6. **Diagramas refletem os quatro adendos dos Contratos**, com destaque para o Adendo 1: o NLB,
   o target group e o listener nascem do Terraform do `oficina-infra-k8s`; o LB Controller apenas
   registra os IPs dos Pods como targets via `TargetGroupBinding`. A razão (ordem de apply) está
   escrita nas notas do diagrama de componentes, não só o fato.

7. **Dashboards separados por público.** Negócio para quem responde pela operação; Plataforma em
   três páginas (API, Cluster/HPA, Lambdas/gateway) para quem responde pelo sistema. Todo widget
   traz `accountIds: [0]` como placeholder — deliberadamente inválido, para forçar a substituição
   e evitar apontar para a conta errada em silêncio.

8. **Nove condições de alerta**, contra as cinco pedidas. As quatro extras — HPA no teto, Pods
   reiniciando, erro do `jwt-authorizer` e 5xx da API — cobrem lacunas que as cinco originais
   deixavam. A do `jwt-authorizer` é a mais importante das quatro: um erro nele derruba **todas**
   as rotas `/api/**`, não apenas o login.

9. **Cada condição de alerta carrega o runbook no campo `description`.** Alerta sem instrução de
   resposta é ruído.

10. **O roteiro do vídeo é uma defesa de arquitetura, não uma demonstração de funcionalidades.**
    Cada bloco tem o que mostrar na tela *e* a frase que precisa ser dita. Inclui plano B para a
    demo de HPA e uma tabela de erros a evitar.

---

## Divergências e lacunas encontradas nos Contratos

Em ordem de gravidade. As duas primeiras exigem decisão de quem consolida.

### 1. `totalAmount` não existe no contrato de custom events — bloqueia o ticket médio

A seção 7 dos Contratos define `ServiceOrderStatusChanged` com `orderId`, `fromStatus`,
`toStatus`, `durationSeconds`, `correlationId` e `env`. **Não há nenhum atributo monetário.**

O backlog E13 pede explicitamente **ticket médio** no dashboard de negócio, e não há como calculá-lo
a partir dos atributos contratados. Os painéis "Ticket médio da OS" e "Ticket médio e faturamento
por dia" foram escritos consultando `average(totalAmount)` e **ficarão vazios** até que o contrato
seja emendado.

**Proposta:** adendo à seção 7 acrescentando `totalAmount` (float) a `ServiceOrderStatusChanged`,
ao menos nas transições para `FINISHED` e `DELIVERED` — o valor já está no agregado
(`ServiceOrder::getTotalAmount()`), então é uma linha no `NewRelicSubscriber` (WS-D12). Alternativa
menos boa: emitir um terceiro evento `ServiceOrderFinished`.

**Ação necessária:** decisão do orquestrador + ajuste no WS-D12.

### 2. Nomes de entidade das Lambdas e do container não estão contratados

As consultas dos dashboards e dos alertas assumem:

| Assumido | Precisa ser confirmado contra |
|---|---|
| `entityName = 'oficina-prod-auth-cpf'` | Terraform do `oficina-lambda-auth` (WS-C9) |
| `entityName = 'oficina-prod-jwt-authorizer'` | idem |
| `containerName = 'php-fpm'` | `deploy/base/api-deployment.yaml` (WS-D17) |
| `hpaName = 'oficina-api'`, `deploymentName = 'oficina-api'` | `deploy/base` |
| `namespaceName = 'oficina-prod'` | `/oficina/prod/eks/namespace` — este **está** contratado |
| campos `status` e `routeKey` no access log do gateway | formato do access log JSON (WS-C10) |

Os Contratos fixam o **prefixo de recursos** (`oficina-<env>`), mas não os nomes finais das
funções, do Deployment nem dos containers. Como as consultas NRQL filtram por esses nomes, uma
divergência produz painel vazio sem erro visível. Está documentado na tabela de divergências do
`newrelic/README.md`.

**Ação necessária:** conferência cruzada na integração (WS-F5), ou um adendo fixando os nomes.

### 3. O nome do repositório `oficina-infra-database` não descreve o que ele contém

Consequência direta da ADR-009: o repositório possui VPC, subnets, security groups e **o segredo
de JWT** — além do banco. O nome sugere um escopo bem menor do que o real, e alguém que leia só a
estrutura de repositórios vai procurar a rede no lugar errado.

`oficina-infra-foundation` descreveria melhor. Não foi proposta a troca porque o nome está fixado
nos Contratos, no plano de entrega e provavelmente já nos scripts de bootstrap. Registrado na
própria ADR-009 como candidato a rename futuro.

**Ação necessária:** nenhuma nesta fase. É informação, não bloqueio.

### 4. Coisas que os Contratos não cobrem e que apareceram ao documentar

- **`max_connections` do RDS × réplicas × `pm.max_children`.** Com `db.t4g.micro` (~90 conexões) e
  até 10 réplicas, o número de workers FPM por Pod precisa ser dimensionado, ou o banco esgota
  conexões justamente no pico — durante a demo de autoescalonamento do vídeo. Registrado como
  questão em aberto na RFC-002; **não** é um problema de documentação, é um risco real de
  execução.
- **`parts_inventory.version` pode ficar decorativo.** O campo foi criado no `002`, mas o contrato
  não diz que a aplicação deve usá-lo em locking otimista. Sem isso, a proteção contra concorrência
  fica só no `CHECK (stock_quantity >= 0)` — que evita estoque negativo, mas transforma a corrida
  em erro de banco em vez de conflito tratado.
- **Não há contrato de `Service`/`Deployment` name**, embora o `TargetGroupBinding` e as consultas
  de observabilidade dependam deles (ver item 2).

---

## Dependente de ação humana

Estes itens são de WS-E e devem entrar no `PENDENCIAS.md` consolidado:

| # | Ação | Critério de "feito" |
|---|---|---|
| 1 | Criar conta New Relic e obter **account ID**, **license key** e **user API key** | As três chaves em mãos; license key gravada como secret `NEW_RELIC_LICENSE_KEY` nos quatro repositórios |
| 2 | Substituir `"accountIds": [0]` nos dois JSONs de dashboard pelo account ID real | `sed` executado; nenhum `[0]` restante nos arquivos importados |
| 3 | Importar os dois dashboards (**Dashboards → Import dashboard**) | Ambos criados e **com dado** em todos os painéis, exceto os dois de ticket médio (dependem da divergência 1) |
| 4 | Criar a política de alertas e as 9 condições (interface ou NerdGraph) | Política visível em *Alerts → Alert policies* com 9 condições habilitadas |
| 5 | Criar o monitor Synthetic **`oficina-prod-health`** apontando para `<apigw-endpoint>/api/health` | Monitor `ENABLED` em duas localidades, com execuções bem-sucedidas no histórico |
| 6 | Criar destino de e-mail e os dois workflows de notificação | *Send test notification* recebido na caixa de entrada |
| 7 | Substituir os placeholders `<id da política>`, `<id do canal de e-mail>` e `<id do destino de e-mail>` em `politica-notificacao.json` | Arquivo sem `<...>`, servindo como documentação do que foi criado |
| 8 | Decidir sobre a divergência 1 (`totalAmount`) e emendar o contrato | Adendo escrito na seção 7 + WS-D12 ajustado |
| 9 | Conferir os nomes assumidos na divergência 2 contra o Terraform e o `deploy/` | Tabela do `newrelic/README.md` revisada, sem "assumido" |
| 10 | Preencher o link do vídeo no README (`Entregáveis da Fase 3`) e nos entregáveis | Link do YouTube/Vimeo no lugar de `_adicionar link_` |
| 11 | Gravar o vídeo seguindo `ROTEIRO-VIDEO.md`, cumprindo a checklist de preparação prévia | Vídeo ≤ 15:00, sem segredo visível em tela, com áudio audível |
| 12 | Ensaiar o vídeo cronometrado ao menos uma vez, com plano B da demo de HPA gravado | Ensaio feito; gravação de reserva do HPA disponível |

---

## Observação final

A qualidade da documentação está limitada, em dois pontos, por informação que só existe depois da
integração: os **nomes reais das entidades** (divergência 2) e a **decisão sobre `totalAmount`**
(divergência 1). Ambos estão sinalizados dentro dos próprios artefatos — na tabela de divergências
do `newrelic/README.md` — e não apenas neste relatório, para que quem for importar os dashboards
tropece no aviso antes de tropeçar num painel vazio.
