# ADR-005 · `reconstitute()` para hidratação do agregado

## Status

Aceita (Fase 1) · Mantida na Fase 3.

## Contexto

Um agregado tem dois caminhos de nascimento com semânticas opostas:

1. **Criação** — é um fato novo do negócio. Deve validar invariantes, aplicar valores iniciais
   (`status = RECEIVED`), consumir estoque e **emitir eventos de domínio**.
2. **Reidratação** — o objeto já existia; o repositório só está remontando um estado que já é
   verdade no banco. Não deve validar transições passadas, não deve consumir estoque de novo e,
   sobretudo, **não deve emitir evento nenhum**.

Antes desta decisão o repositório usava o construtor e depois "corrigia" o objeto com setters
públicos (`setStatus()`, `setTotalAmount()`, `setCreatedAt()`). O efeito colateral era grave: ler
uma OS do banco disparava `decreaseStock()` outra vez e registrava um `ServiceOrderStatusChanged`
espúrio — um `GET` produzia email. Além disso, os setters existiam apenas para servir à
infraestrutura, mas ficavam disponíveis para qualquer código do domínio, destruindo as garantias
da ADR-003.

## Decisão

Tornar o construtor de `ServiceOrder` **privado** e expor dois caminhos explícitos:

- **`ServiceOrder::create(...)`** — factory de criação. Valida, define `RECEIVED`, consome estoque
  e registra o evento de criação. É a única porta para uma OS nova.
- **`ServiceOrder::reconstitute(...)`** — factory de reidratação, usada **apenas** pelos
  repositórios. Recebe o estado completo já persistido (id, status, total, `created_at`, itens) e
  o injeta diretamente, **sem validar transições, sem tocar em estoque e sem registrar eventos**.

Nenhum setter público de estado permanece na classe. `isNew()` distingue os dois casos quando o
repositório precisa decidir entre `INSERT` e `UPDATE`.

## Consequências

**Positivas**

- Leitura virou uma operação sem efeito colateral. O bug de estoque decrementado em `GET` deixou
  de ser possível por construção.
- As invariantes da máquina de estados voltam a valer: sem setter público, o único jeito de mudar
  status é uma transição nomeada.
- A intenção fica explícita no nome — quem lê `reconstitute()` sabe imediatamente que aquilo veio
  do banco.
- O padrão se replicou naturalmente para as demais entidades, dando consistência ao código.

**Negativas**

- `reconstitute()` é público e, portanto, tecnicamente chamável de fora da infraestrutura. Nada
  na linguagem impede um Use Case de usá-lo para burlar a máquina de estados; a proteção é
  convenção e revisão de código. (PHP não tem `internal`.)
- A assinatura é longa — recebe todo o estado do agregado — e precisa ser atualizada sempre que o
  agregado ganhar um campo. Isso propaga para os repositórios e para os testes.
- Duplicação aparente entre `create()` e `reconstitute()` na atribuição de propriedades.
- Um mapeador que confunda a ordem dos parâmetros produz um agregado silenciosamente errado; o
  teste de integração é a rede de segurança.

## Alternativas consideradas

| Alternativa | Por que não |
|---|---|
| **Setters públicos (estado anterior)** | Era o problema: efeitos colaterais na leitura e invariantes furadas. |
| **Reflection para popular propriedades privadas** | Elimina a API pública extra, mas torna o mapeamento invisível, quebra a análise estática do PHPStan nível 8 e falha em silêncio quando um campo é renomeado. |
| **Construtor público com flag `$isNew`** | Um parâmetro booleano que muda a semântica do objeto é exatamente o tipo de acoplamento temporal que se quer evitar; e o construtor continuaria disparando lógica condicional. |
| **ORM com hidratação própria (Doctrine)** | Resolveria o problema de fábrica, mas traz um ORM inteiro contra a ADR-001, além de proxies e lazy loading que escondem o custo das consultas. |
| **Event sourcing (reconstruir por replay de eventos)** | Elimina o problema na raiz, mas é uma mudança de paradigma de persistência desproporcional ao domínio e ao prazo. |
