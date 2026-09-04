# ADR-004 · Controle transacional dentro do repositório

## Status

Aceita (Fase 1) · Mantida na Fase 3.

## Contexto

Persistir uma Ordem de Serviço não é uma escrita: é o `INSERT`/`UPDATE` da OS, mais as linhas de
`service_order_services`, mais as de `service_order_parts`, mais o decremento de
`parts_inventory.stock_quantity`. Se qualquer uma dessas etapas falhar e as anteriores forem
confirmadas, o sistema fica com estoque baixado sem OS, ou OS sem itens — inconsistência que não
se corrige sozinha e que é exatamente o motivo de termos escolhido um banco ACID.

A pergunta arquitetural é **quem abre a transação**. As opções clássicas são: o Use Case (que
conhece a intenção de negócio, mas passaria a conhecer `PDO::beginTransaction`), um Unit of Work
explícito (que conhece a intenção sem conhecer o driver, ao custo de uma abstração a mais), ou o
próprio repositório (que já é a fronteira de persistência).

Na Fase 3 nada mudou tecnicamente — o RDS MySQL 8.0 substituiu o MySQL em container e o
comportamento transacional é o mesmo. O que mudou foi o contexto de concorrência: com 2 a 10
réplicas, duas requisições podem consumir a mesma peça simultaneamente. Por isso o
`002_fase3_ajustes.sql` acrescenta `parts_inventory.version` e `CHECK (stock_quantity >= 0)`.

## Decisão

O **repositório** é o dono do escopo transacional. `PdoServiceOrderRepository::save()` abre a
transação, persiste o agregado inteiro — OS, serviços, peças e baixa de estoque — e faz commit ou
rollback. O Use Case chama `save()` e não sabe que existe transação.

A regra é: **um agregado, uma chamada de `save()`, uma transação**. O limite transacional coincide
com o limite do agregado, como manda o DDD tático.

Os eventos de domínio só são despachados **depois** do commit, via `releaseEvents()` na camada de
aplicação — não se notifica cliente nem se emite telemetria de algo que pode ter sofrido rollback.

## Consequências

**Positivas**

- O Use Case permanece livre de infraestrutura: nenhuma menção a PDO, transação ou SQL.
- Atomicidade garantida no ponto exato onde ela importa; é impossível chamar `save()` "sem
  transação" por esquecimento.
- Fácil de testar: o teste de integração em SQLite in-memory exercita o caminho completo,
  incluindo rollback.
- A ordem "commit primeiro, eventos depois" evita email e custom event de OS que não existe.

**Negativas**

- **Não compõe.** Uma operação que precise atualizar dois agregados atomicamente não tem como ser
  expressa: seriam duas transações independentes. Aceitamos isso porque nenhum caso de uso atual
  exige — e, se exigir, a resposta correta é revisar as fronteiras dos agregados, não abrir a
  transação para fora.
- Transações aninhadas não são suportadas; chamar `save()` de dentro de outra transação
  quebraria. Não há proteção contra isso além da convenção.
- O repositório acumula responsabilidade: além de mapear, coordena consistência.
- O escopo transacional fica invisível para quem lê apenas o Use Case — só o nome `save()` sugere
  a atomicidade. Mitigado por documentação e teste.

## Alternativas consideradas

| Alternativa | Por que não |
|---|---|
| **Transação no Use Case** | Vaza `PDO` (ou uma abstração de transação) para a camada de aplicação, que passaria a depender de infraestrutura. É a violação de camadas mais comum em projetos PHP. |
| **Unit of Work explícito** | Tecnicamente a melhor resposta e a que resolveria a composição entre agregados. Descartada por excesso de maquinário para um domínio com um único agregado transacional relevante. É a evolução natural caso um segundo apareça. |
| **Decorator/middleware transacional em torno do Use Case** | Elegante, mas exigiria um container de DI com interceptação — mágica que a ADR-001 recusa. |
| **Consistência eventual (saga)** | Desproporcional. As escritas são locais ao mesmo banco; distribuir uma transação que já é local só adiciona modos de falha. |
