# Roteiro do vídeo de entrega — Tech Challenge Fase 3

**Duração alvo:** 15 minutos (limite rígido). **Formato:** gravação de tela com narração.
**Idioma:** português do Brasil.

O vídeo é avaliado. Ele não é uma demonstração de que "funciona" — é a **defesa da arquitetura**.
A regra que orienta todo o roteiro: **a cada tela mostrada, diga por que a decisão foi tomada,
não apenas o que está ali.** Um `kubectl get pods` sem explicação não vale nada; o mesmo comando
explicando por que há duas réplicas no mínimo vale a nota.

---

> **A gravação é em homologação.** O ambiente `prod` não foi provisionado, por decisão de custo:
> cada cluster EKS tem control plane próprio a ~US$ 73/mês, e um segundo ambiente completo
> praticamente dobraria a conta do projeto.
>
> O enunciado pede deploy automático das branches de homologação **e** de produção. Isso está
> **implementado e visível** nos quatro repositórios — `push` em `develop` implanta em
> homologação, `push` em `main` implanta em produção, com GitHub Environments distintos. O que
> não existe é a infraestrutura de produção provisionada.
>
> **Diga isso em voz alta no vídeo, no Bloco 4**, uma frase, sem rodeio: *"o pipeline de produção
> está implementado e é o mesmo; não deixei o ambiente de pé porque um segundo control plane de
> EKS dobraria o custo do projeto."* Ser direto sobre um trade-off consciente vale mais do que
> torcer para ninguém reparar — e reparam.

## Preparação prévia — checklist

Faça tudo isto **antes** de apertar o gravar. Nada aqui deve acontecer ao vivo.

### Ambiente

- [ ] Os quatro repositórios aplicados e funcionando em **`hml`**, na ordem
      **database → k8s → lambda → app**.
- [ ] `terraform apply` de todos os stacks concluído **sem erro** e sem `plan` pendente.
- [ ] Migrations aplicadas; `hml` com o `003_seed_demo.sql` (30 dias de OS sintéticas) — é ele que
      faz os dashboards de negócio terem forma em vez de uma linha reta.
- [ ] `curl https://lkdvezfrm5.execute-api.us-east-1.amazonaws.com/api/health` respondendo 200.
- [ ] CPFs do seed anotados e testados — são os quatro ramos que você vai demonstrar:

      | CPF | Cliente | Resposta |
      |---|---|---|
      | `78901428385` | Ana Paula Ribeiro, `ACTIVE` | 200 com token |
      | `94064914198` | Isabela, `INACTIVE` | 403 |
      | `02407397878` | Joao Vitor, `BLOCKED` | 403 |
      | `52998224725` | não cadastrado (DV válido) | 404 |
      | `12345678900` | DV inválido | 400 |
- [ ] Dashboards do New Relic importados **e com dado** (importe cedo, não na véspera).
- [ ] Alertas criados e notificação testada.
- [ ] Monitor Synthetic rodando há tempo suficiente para ter histórico.

### Materiais em tela

- [ ] Diagrama de componentes aberto em uma aba (`docs/fase-3/diagramas/componentes.md`
      renderizado — o GitHub renderiza Mermaid nativamente).
- [ ] Aba do índice de ADRs.
- [ ] Postman com a coleção importada e a variável de ambiente do endpoint já preenchida.
- [ ] Terminal com **fonte grande** (≥ 16 pt) e prompt curto. Ninguém consegue ler `12px` num
      vídeo comprimido.
- [ ] Abas do navegador **pré-abertas e na ordem do roteiro**: GitHub (4 repos), Actions, Console
      AWS (EKS, RDS, API Gateway, SSM), New Relic (2 dashboards, alertas).
- [ ] Segredos, tokens e ARNs de conta **fora da tela**. Confira antes: uma license key visível no
      vídeo é um incidente de segurança real.
- [ ] Um PR já aberto e pronto para ser mesclado — o pipeline vai rodar durante o vídeo.
- [ ] Script de carga pronto para colar (`hey`, `ab` ou um `while` com `curl`).

### Ensaio

- [ ] **Ensaie uma vez cronometrando.** Estourar 15 minutos é o erro mais comum.
- [ ] Tenha um **plano B gravado**: se a demo do HPA não escalar ao vivo, use uma gravação prévia
      ou um print do dashboard. Não improvise diante da câmera.
- [ ] Teste o áudio. Áudio ruim derruba a percepção de qualidade mais que qualquer outra coisa.

---

## Roteiro minutado

### Bloco 1 · Abertura e problema (0:00 – 1:00)

**Na tela:** slide ou README do repositório principal.

- Quem é você, qual o sistema (oficina mecânica: recepção → diagnóstico → orçamento → aprovação →
  execução → entrega).
- O que a Fase 3 pede: separação em repositórios, nuvem, autenticação do cliente, observabilidade.
- **A frase que ancora o vídeo:** "a Fase 2 entregava um monolito containerizado num cluster
  local; a Fase 3 entrega quatro artefatos com ciclos de vida próprios, rodando em AWS."

> Não gaste mais de um minuto aqui. O avaliador quer ver arquitetura, não introdução.

---

### Bloco 2 · Arquitetura de nuvem (1:00 – 3:30)

**Na tela:** `docs/fase-3/diagramas/componentes.md` renderizado.

Percorra o diagrama seguindo o caminho de uma requisição — e não componente por componente:

- Cliente → **API Gateway HTTP API** (único ponto de entrada).
- `POST /auth/cpf` → **Lambda `auth-cpf`** → RDS → devolve JWT.
- `ANY /api/{proxy+}` → **Lambda `jwt-authorizer`** → **VPC Link** → **NLB interno** → Pods no EKS.
- **RDS em subnet privada**, acessível só pelo SG cliente de banco.
- **Secrets Manager** → External Secrets Operator → `Secret` no namespace.
- New Relic recebendo APM, logs, métricas de cluster e métricas de Lambda.

**Três frases que precisam ser ditas:**

1. "O NLB é **interno** — o cluster não tem endereço público; o único caminho de entrada é o
   gateway."
2. "Os nodes ficam em **subnet pública sem NAT Gateway**. Foi uma decisão de custo, está
   documentada na ADR-010, e eu explico o que se perde com ela." *(Antecipar a crítica é mais
   forte do que esperar que a banca a levante.)*
3. "O NLB e o target group nascem do **Terraform do repositório de cluster**, não do `Service` da
   aplicação — porque o repositório da Lambda aplica antes e precisa do ARN do listener."

---

### Bloco 3 · Os quatro repositórios e o acoplamento por SSM (3:30 – 5:30)

**Na tela:** as quatro abas do GitHub, depois o terminal.

- Por que a divisão é por **ciclo de vida** e não por camada técnica: o banco muda uma vez, a
  aplicação muda todo dia.
- **ADR-009:** o repositório de banco é a camada de fundação — rede, dados **e segredos**.
  A frase decisiva: *"eu consigo destruir o cluster inteiro para economizar e recriar amanhã, e
  nenhum dado se perde nem nenhum token é invalidado."*
- **ADR-008:** nenhum repositório usa `terraform_remote_state`; o acoplamento é por SSM.

**Comando ao vivo — este é o momento mais forte do bloco:**

```bash
aws ssm get-parameters-by-path --path /oficina/hml --recursive \
  --query 'Parameters[].Name' --output table
```

"Isto que está na tela é a **interface pública** entre os quatro repositórios. Não é um arquivo de
state que alguém precisa saber onde está: é um contrato nomeado, legível, e com permissão
granular."

---

### Bloco 4 · CI/CD ao vivo (5:30 – 7:30)

**Na tela:** GitHub → PR aberto → Actions.

- Mostre o `pr.yml` já executado no PR: lint, PHPUnit, PHPStan nível 8, e o `terraform plan`
  comentado no PR nos repositórios de IaC.
- **Faça o merge ao vivo** e deixe o `deploy.yml` rodando em segundo plano — você volta a ele no
  bloco 8.
- Enquanto roda, mostre o trecho do workflow e diga: *"autenticação na AWS por **OIDC**, com
  `id-token: write` e `role-to-assume`. Não existe access key estática em nenhum dos quatro
  repositórios."*
- Aponte a sequência: build → ECR → `kubectl apply -k` → Job de migration → smoke test → marcação
  do deployment no New Relic.

---

### Bloco 5 · Autenticação por CPF (7:30 – 9:30)

**Na tela:** Postman.

1. `POST /auth/cpf` com CPF **válido e ativo** → 200, token e dados do cliente. Cole o token em
   [jwt.io](https://jwt.io) e mostre as claims: `sub`, `role: customer`, `cpf`, `exp`.
2. **Os ramos de erro, em sequência rápida:** CPF com dígito inválido → 400; CPF não cadastrado →
   404; cliente bloqueado → 403.
3. `GET /api/service-orders/me` com o token → só as OS daquele cliente.
4. **O momento mais importante do bloco:** `GET /api/service-orders/status?document=...` →
   **404**. *"Essa rota existia na Fase 2. Ela aceitava CPF e placa por query string, sem
   autenticação nenhuma, e devolvia a OS de qualquer pessoa cujo CPF você conhecesse. Foi
   removida."*
5. Tente uma rota de admin com o token de cliente → **403**.

**Diga o ponto técnico:** *"o JWT Authorizer nativo do API Gateway só valida emissores OIDC com
JWKS — ele não valida HS256, porque precisaria conhecer o segredo compartilhado. Por isso a
validação está num **Lambda Authorizer REQUEST**, com cache de 300 segundos para não invocar a
função a cada requisição. O caminho para RS256 com JWKS está na RFC-003."*

E complete com a defesa em profundidade: *"o Pod **revalida** o token localmente. Ele nunca confia
em header injetado pelo gateway."*

---

### Bloco 6 · Fluxo de negócio da OS (9:30 – 11:00)

**Na tela:** Postman, com o token de admin.

- `POST /api/service-orders` → 201.
- `POST /api/service-orders/{id}/items` → orçamento recalculado, estoque reservado.
- `PATCH /api/service-orders/{id}/status` avançando o estado.
- **Tente uma transição inválida** (por exemplo, `RECEIVED → DELIVERED`) → erro de domínio.
  *"Isso não é uma validação de controller: é o Aggregate Root recusando. Cada transição é um
  método nomeado — `approve()`, `reject()`, `finish()` — e não existe `setStatus()` na classe.
  ADR-003."*
- `POST /api/service-orders/{id}/approval` com o `X-Webhook-Token` → aprovação externa.
- Mostre a mesma chamada **sem** o header → rejeitada.

---

### Bloco 7 · Autoescalonamento (11:00 – 12:30)

**Na tela:** terminal dividido — `kubectl get hpa -w` de um lado, `kubectl get pods -w` do outro.

```bash
kubectl -n oficina-hml get hpa oficina-api -w
hey -z 90s -c 60 https://<endpoint>/api/service-orders/me -H "Authorization: Bearer $TOKEN"
```

- Mostre o estado de repouso: 2 réplicas.
- Aplique a carga; narre a CPU subindo e o HPA reagindo até o teto de 10.
- **ADR-007, dito em uma frase:** *"70% de CPU e 80% de memória. 70 e não 90 porque o Pod novo
  leva de 15 a 30 segundos para ficar pronto — se eu escalar a 90%, a latência já degradou antes
  do reforço chegar. E o mínimo de 2 não é sobre carga, é sobre disponibilidade durante rollout."*
- Diga por que **não** KEDA: não há fila para medir, e `scale-to-zero` quebraria o mínimo de 2.

> Se a carga não fizer efeito ao vivo, corte para o dashboard de plataforma mostrando um teste
> anterior. **Tenha isso pronto.**

---

### Bloco 8 · Observabilidade (12:30 – 14:00)

**Na tela:** New Relic.

1. **Dashboard de Plataforma** — o pico de réplicas que acabou de acontecer, já visível no painel
   "HPA — réplicas atuais x mínimo x máximo". Latência p50/p95/p99, throughput por rota, CPU e
   memória por Pod, duração e cold start das Lambdas.
2. **Dashboard de Negócio** — volume diário de OS, funil de status, tempo médio por status.
   *"Estes números não vêm de instrumentação manual espalhada pelo código: vêm dos **eventos de
   domínio** que o agregado já emitia. O `NewRelicSubscriber` é só mais um assinante, ao lado do
   notificador de email e do histórico de status."*
3. **Log estruturado** — filtre por um `correlation_id` e mostre a linha JSON.
   *"Esse identificador vem do header `X-Request-Id`, ou do `X-Amzn-Trace-Id` que o gateway
   injeta, e volta sempre na resposta. É o que liga um erro relatado pelo usuário à linha de log e
   ao evento de negócio."*
4. **Alertas** — a lista de condições. Cite duas: p95 acima de 1500 ms e erro da Lambda
   `auth-cpf` acima de 1%.
5. Volte ao **Actions**: o deploy do bloco 4 terminou. Mostre o smoke test verde.

---

### Bloco 9 · Fechamento (14:00 – 15:00)

**Na tela:** índice das ADRs e das RFCs.

- Aponte que as decisões estão documentadas: **10 ADRs** e **3 RFCs**, com alternativas avaliadas
  e consequências negativas assumidas.
- **Seja honesto sobre os limites** — isso soma nota, não subtrai:
  - nodes em subnet pública, sem NAT: economia de ~US$ 33/mês contra uma camada de defesa em
    profundidade; não seria aceitável com dado real (ADR-010);
  - CPF sem segundo fator é identificação, não autenticação forte; mitigado por autorização
    restrita (RFC-003);
  - HS256 com segredo compartilhado; o caminho para RS256 com JWKS está desenhado;
  - RDS sem Multi-AZ, por custo.
- Encerre: *"a Clean Architecture se pagou aqui — o domínio não mudou uma linha para migrar de um
  cluster local para EKS com API Gateway e Lambda."*

---

## Erros a evitar

| Erro | Por quê |
|---|---|
| Ler o código linha a linha | O avaliador quer decisão e trade-off, não sintaxe |
| Deixar `terraform apply` rodando ao vivo | Consome minutos e pode falhar |
| Mostrar tela com segredo, ARN de conta ou license key | Incidente de segurança real |
| Fonte pequena no terminal | Ilegível depois da compressão do vídeo |
| Estourar os 15 minutos | Costuma custar nota diretamente |
| Esconder as fraquezas da arquitetura | A banca encontra sozinha; assumir demonstra maturidade |
| Improvisar quando algo falha | Tenha o plano B gravado |

## Distribuição do tempo — referência rápida

| Bloco | Assunto | Duração |
|---|---|---|
| 1 | Abertura e problema | 1:00 |
| 2 | Arquitetura de nuvem | 2:30 |
| 3 | Quatro repositórios e SSM | 2:00 |
| 4 | CI/CD ao vivo | 2:00 |
| 5 | Autenticação por CPF | 2:00 |
| 6 | Fluxo de negócio da OS | 1:30 |
| 7 | Autoescalonamento | 1:30 |
| 8 | Observabilidade | 1:30 |
| 9 | Fechamento | 1:00 |
| | **Total** | **15:00** |
