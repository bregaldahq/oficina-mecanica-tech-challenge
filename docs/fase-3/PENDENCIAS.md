# Pendências — o que depende de você

Estado real do projeto e o que falta para a entrega. Atualizado após a execução completa
dos Blocos 0, 1 e 2 em homologação.

Legenda de esforço: 🟢 minutos · 🟡 até 1 hora · 🔴 mais de 1 hora ou espera externa

---

## Estado atual

**O sistema está no ar e validado ponta a ponta em homologação.**

`https://lkdvezfrm5.execute-api.us-east-1.amazonaws.com`

| Verificação executada ao vivo | Resultado |
|---|---|
| `POST /auth/cpf` com CPF de cliente ativo | **200** com JWT |
| `POST /auth/cpf` com CPF de cliente inativo | **403** `Cliente inativo. Procure a oficina.` |
| `POST /auth/cpf` com CPF válido não cadastrado | **404** `Cliente não encontrado.` |
| `POST /auth/cpf` com dígito verificador inválido | **400** `CPF inválido.` |
| `GET /api/service-orders/me` com o token da Lambda | **200**, escopado pelo `sub` |
| `GET /api/customers` com token de cliente | **403** `Acesso negado.` |
| Rota protegida sem token | **401** no gateway |

O contrato de JWT entre a função serverless e a aplicação — repositórios separados, deploys
independentes — está provado em produção, não apenas em teste.

| Camada | Estado (`hml`) |
|---|---|
| VPC, RDS MySQL, Secrets Manager, VPC endpoint, 10 parâmetros SSM | ✅ |
| EKS, node group `t3.medium`, 4 add-ons Helm, ECR, NLB, RBAC | ✅ |
| API Gateway, VPC Link, Lambda de CPF, Lambda authorizer | ✅ |
| Aplicação: migrations aplicadas, rollout, smoke test em `/api/ready` | ✅ |
| Código: 184 testes / 377 asserções, PHPStan nível 8, PSR-12 | ✅ |
| Ambiente `prod` | ❌ nada provisionado |

---

## Bloco 0 · Contas e bootstrap — ✅ concluído

Tudo feito, com uma pendência que deixou de ser bloqueante:

> **0.7 · Aumento de quota de vCPU** — segue aguardando aprovação da AWS. **Deixou de importar**
> para o caminho crítico: os nodes passaram de `t3.small` para `t3.medium`, que tem os mesmos
> 2 vCPU, então o cluster subiu dentro da quota atual. Só volta a pesar se o HPA precisar escalar
> para 4 nodes durante a gravação.

---

## Bloco 1 · Repositórios GitHub — ✅ concluído

Os quatro repositórios estão públicos, com `main` e `develop` **de conteúdo idêntico**, proteção
aplicada (PR obrigatório, 1 aprovação, sem force-push, sem deleção) e secrets configurados.

| Repositório | Papel |
|---|---|
| [oficina-mecanica-tech-challenge](https://github.com/bregaldahq/oficina-mecanica-tech-challenge) | Aplicação PHP + manifests do workload |
| [oficina-infra-database](https://github.com/bregaldahq/oficina-infra-database) | VPC, RDS, segredos, migrations |
| [oficina-infra-k8s](https://github.com/bregaldahq/oficina-infra-k8s) | EKS, add-ons, ECR, NLB |
| [oficina-lambda-auth](https://github.com/bregaldahq/oficina-lambda-auth) | Lambdas Bref + API Gateway |

**Falta:**

| # | Ação | Esforço | Feito quando |
|---|---|---|---|
| 1.1 | Confirmar o **aceite** dos 3 convites de `soat-architecture` | 🟢 depende do avaliador | Aceito — **tire print de cada um para o PDF** |
| 1.2 | Marcar os status checks como obrigatórios na proteção de branch | 🟢 | Aparecem como *required* (só listáveis após rodarem uma vez) |

> **`enforce_admins` está desligado.** Foi necessário: o GitHub não permite aprovar o próprio PR,
> e com ele ligado você ficava sem conseguir mergear nada trabalhando sozinho. Push direto
> continua bloqueado e PR continua obrigatório — a demonstração do vídeo não é afetada.

> **Nunca use `--delete-branch` em PR de sincronização.** Num PR `develop → main` o *head* é uma
> branch permanente, e a flag manda apagá-la. Aconteceu aqui; só não houve perda porque
> `allow_deletions: false` recusou a operação.

---

## Bloco 2 · Provisionamento AWS — ✅ `hml` · ❌ `prod`

| # | Ação | Esforço | Feito quando |
|---|---|---|---|
| 2.1 | Provisionar `prod` na ordem **database → k8s → lambda → app** | 🔴 ~40 min | Endpoint de produção respondendo |
| 2.2 | Restringir `cluster_endpoint_public_access_cidrs` em `envs/prod.tfvars` (hoje `0.0.0.0/0`) | 🟢 | CIDR limitado ao seu IP |
| 2.3 | Confirmar `capacity_type = ON_DEMAND` no ambiente avaliado | 🟢 | Já está assim em `prod.tfvars` |

Cada repositório tem `workflow_dispatch`; o de banco aceita o ambiente como input:

```bash
gh workflow run Deploy --repo bregaldahq/oficina-infra-database --ref main -f environment=prod
```

> O `plan (prod)` do repositório de Kubernetes **falha hoje de propósito**: os parâmetros SSM de
> produção não existem. Passa a verde assim que o stack de banco for aplicado em `prod`.

### Armadilhas confirmadas na prática

Todas estas foram encontradas rodando o pipeline de verdade e já estão corrigidas no código —
ficam registradas porque voltam a morder em `prod`:

- **`sub` do OIDC do GitHub mudou de formato.** Agora vem `repo:owner@ownerId/repo@repoId:...`
  com IDs imutáveis. O padrão `repo:owner/nome:*` que todo tutorial ensina **não casa mais**, e o
  erro é um `AccessDenied` genérico sem pista. Só o CloudTrail revela o `sub` real.
- **Sem NAT, subnet privada não alcança serviço público da AWS.** A Lambda ficava pendurada até o
  timeout de 15s chamando o Secrets Manager. Resolvido com interface VPC endpoint (~US$ 7,20/mês
  por AZ) e regra de egress 443 no SG crachá — que, por definir egress explícito, negava tudo mais.
- **Autorização do EKS é separada da de IAM.** A role da aplicação com `AdministratorAccess` ainda
  recebia `Unauthorized` do kubectl até ganhar uma *access entry*, e os CRDs
  (`TargetGroupBinding`, `ExternalSecret`) exigiram RBAC próprio além dela.
- **Limite de pods por node vem do VPC CNI, não de CPU.** `t3.small` suporta 11 pods e os add-ons
  consumiam os 22 slots dos dois nodes.
- **`cp -a` falha em container não-root.** Consequência da migração de Alpine (uid 82) para Debian
  (uid 33): o initContainer não consegue preservar atributos no `emptyDir`, e o pod fica preso em
  `PodInitializing`.
- **Performance Insights não existe em classes micro.** `db.t4g.micro` recusa `CreateDBInstance`.
- **`WEBHOOK_TOKEN` virou obrigatório.** Ambiente sem esse valor responde `401` em
  `POST /api/service-orders/{id}/approval`.
- **`deletion_protection = true` em `prod`** faz o `destroy` falhar por projeto.

---

## Bloco 3 · Observabilidade — ❌ próximo passo

| # | Ação | Esforço | Feito quando |
|---|---|---|---|
| 3.1 | Rodar `scripts/newrelic-import.py` com as chaves em mãos | 🟢 | Dashboards e alertas criados |
| 3.2 | Conferir que os painéis têm **dado**, não só estrutura | 🟢 | Gráficos populados |
| 3.3 | Criar o monitor Synthetic apontando para `<endpoint>/api/health` | 🟢 | `ENABLED` em 2 localidades |
| 3.4 | Criar destino de e-mail e workflow de notificação | 🟢 | *Send test notification* recebido |

> ⚠️ **Os dashboards consultam o ambiente `prod`.** As NRQL filtram por
> `appName = 'oficina-api-prod'` e log group `%oficina-prod-api%`. Como só existe `hml`, eles
> ficariam **vazios sem dar erro**. O script de importação aceita o ambiente como parâmetro e
> reescreve as consultas — importe com `hml` enquanto produção não existir.

---

## Bloco 4 · Entrega

| # | Ação | Esforço | Feito quando |
|---|---|---|---|
| 4.1 | Subir produção **24–48h antes** da gravação e manter no ar até sair a nota | 🔴 | Endpoint respondendo (o enunciado pede "links para os deploys ativos") |
| 4.2 | Ensaiar o vídeo cronometrado, com plano B da demo de HPA gravado | 🟡 | Ensaio feito |
| 4.3 | Gravar seguindo `docs/fase-3/ROTEIRO-VIDEO.md` | 🔴 | ≤ 15:00, **sem segredo em tela** |
| 4.4 | Publicar no YouTube como **não listado** | 🟢 | Link em mãos |
| 4.5 | Preencher o link do vídeo no `README.md` | 🟢 | Sem `_adicionar link_` |
| 4.6 | Montar o PDF: links dos 4 repos, vídeo, documentações, confirmação do avaliador | 🟡 | Submetido no Portal do Aluno |

O seed já populou **120 ordens de serviço distribuídas em 30 dias e nos 7 status**, com histórico
de transições coerente — os dashboards de negócio têm dado real para mostrar.

---

## Custo

| Recurso | Mês |
|---|---|
| EKS control plane | ~US$ 73 |
| 2× t3.medium SPOT (`hml`) | ~US$ 18 |
| NLB interno | ~US$ 17 |
| VPC endpoint Secrets Manager (1 AZ) | ~US$ 7 |
| RDS `db.t4g.micro` | grátis nos 12 primeiros meses |
| API Gateway, Lambda, ECR, Secrets Manager | ~US$ 3 |
| **Total ligado 24/7** | **~US$ 118** |
| **Com o EKS destruído fora das sessões** | **~US$ 30** |

O `terraform destroy` do repositório de Kubernetes não toca em nada stateful — foi por isso que a
VPC, o RDS e os segredos ficaram no repositório de banco. Recriar o cluster leva ~12 minutos.

---

## Decisões em aberto

Nenhuma bloqueia a entrega.

1. **Extensão New Relic da Lambda desligada.** A versão 81 do layer público não é acessível a esta
   conta (`AccessDenied` em `lambda:GetLayerVersion`). O APM da aplicação e o monitoramento do
   cluster seguem ativos; só as métricas nativas das funções ficam de fora. Para reativar,
   confirme a versão vigente em https://layers.newrelic-external.com/ e ligue `newrelic_enabled`.
2. **Nome do repositório `oficina-infra-database`.** Ele possui bem mais que o banco — VPC,
   subnets, security groups e os segredos de autenticação. `oficina-infra-core` descreveria
   melhor. Registrado na ADR-009 como candidato a rename.
3. **`parts_inventory.version`** existe no schema para lock otimista, mas a aplicação ainda não o
   usa. Com o HPA subindo até 10 Pods, duas OS concorrentes podem reservar a mesma peça. O
   `CHECK (stock_quantity >= 0)` impede corrupção, mas a última escrita vence.
4. **Migrations aplicadas pelo repositório da aplicação.** A ordem contratada é
   database → k8s → lambda → app, mas o *schema* só nasce no último passo — então o smoke test da
   Lambda não pode passar num ambiente recém-criado. Funciona; a ordem é que fica menos honesta.
