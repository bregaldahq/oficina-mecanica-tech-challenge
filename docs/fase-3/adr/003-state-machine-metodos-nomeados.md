# ADR-003 · Máquina de estados com métodos nomeados no Aggregate Root

## Status

Aceita (Fase 1) · Mantida na Fase 3, agora também como fonte da telemetria de negócio.

## Contexto

A Ordem de Serviço é o Aggregate Root do sistema e seu ciclo de vida é o próprio negócio:

```
RECEIVED → DIAGNOSIS → AWAITING_APPROVAL → EXECUTING → FINISHED → DELIVERED
                              └─────────→ REJECTED
```

Nem toda transição é permitida, e cada uma tem significado distinto para o negócio: enviar para
aprovação consolida o orçamento; aprovar libera a execução; recusar encerra a OS. Um
`setStatus(string $status)` genérico trataria todas como a mesma operação, empurraria a validação
das regras para quem chama e permitiria escrever `$os->setStatus('DELIVERED')` a partir de
`RECEIVED` sem que nada reclamasse.

O Domain Storytelling (`docs/DOMAIN-STORYTELLING.md`) descreve essas transições com verbos do
domínio — "o Mecânico inicia o Diagnóstico", "o Cliente aprova o Orçamento". A linguagem ubíqua
existe; a questão é se o código a preserva ou a dissolve em um campo de string.

## Decisão

Expor **um método nomeado por transição** no `ServiceOrder`, e nenhum setter público de status:

| Método | De | Para |
|---|---|---|
| `changeToDiagnosis()` | `RECEIVED` | `DIAGNOSIS` |
| `sendForApproval()` | `DIAGNOSIS` | `AWAITING_APPROVAL` |
| `approve()` | `AWAITING_APPROVAL` | `EXECUTING` |
| `reject()` | `AWAITING_APPROVAL` | `REJECTED` |
| `finish()` | `EXECUTING` | `FINISHED` |
| `deliver()` | `FINISHED` | `DELIVERED` |

Cada método valida o estado de origem e lança `InvalidStatusTransitionException` quando a
transição é inválida; em seguida registra um `ServiceOrderStatusChangedEvent` na lista interna de
eventos, liberada por `releaseEvents()` após a persistência.

Na Fase 3 esses eventos ganharam dois novos assinantes além do notificador por email:
`StatusHistorySubscriber` (grava em `service_order_status_history`) e `NewRelicSubscriber` (emite
o custom event `ServiceOrderStatusChanged`). A decisão de modelagem virou, também, a origem da
observabilidade de negócio.

## Consequências

**Positivas**

- Transição inválida é **impossível de expressar** no código chamador — o erro deixa de ser um
  bug de runtime e vira um método que não existe.
- A regra de transição vive num lugar só, dentro do agregado, e é testável sem banco
  (`tests/Unit/ServiceOrderStateTest.php`).
- Os eventos de domínio saem semanticamente corretos por construção, o que permitiu plugar email,
  histórico e telemetria sem tocar no domínio (Open/Closed na prática).
- O código é legível por quem conhece o negócio e não conhece o sistema.

**Negativas**

- Adicionar um status novo exige alterar o agregado, o enum do banco (`002_fase3_ajustes.sql`) e
  os testes — não é configurável em runtime. É o preço de tornar a regra explícita.
- Seis métodos em vez de um: mais superfície pública no agregado.
- Não há uma tabela de transições introspectável, o que dificultaria gerar um diagrama de estados
  automaticamente ou expor as transições válidas por API. Hoje o diagrama é mantido à mão.
- O padrão precisa ser aplicado com disciplina: um único `setStatus()` acrescentado por
  conveniência derruba toda a garantia.

## Alternativas consideradas

| Alternativa | Por que não |
|---|---|
| **`setStatus(string)` com validação interna** | Preserva a validação, mas perde a linguagem ubíqua e permite qualquer string na assinatura. O erro só aparece em runtime. |
| **`transitionTo(ServiceOrderStatus $status)` com enum** | Melhor que a string crua e type-safe, mas ainda trata "aprovar" e "recusar" como a mesma operação, o que impede eventos distintos sem um `match` no meio. |
| **Biblioteca de state machine (ex.: Symfony Workflow)** | Traz configuração declarativa e introspecção, ao custo de uma dependência de framework — contra a ADR-001 — e de mover a regra do domínio para um arquivo de configuração. |
| **State Pattern (uma classe por estado)** | Correto e extensível, mas seis classes para uma máquina linear de sete estados é cerimônia sem retorno neste tamanho de domínio. |
