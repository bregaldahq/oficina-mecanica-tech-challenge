# ADR-001 · PHP 8.2 puro, sem framework MVC

## Status

Aceita (Fase 1) · Mantida na Fase 3.

## Contexto

O Tech Challenge pede um back-end que evidencie domínio de arquitetura de software, não
produtividade de scaffolding. O sistema de oficina mecânica tem um núcleo de negócio relevante
(máquina de estados da Ordem de Serviço, cálculo de orçamento, consumo de estoque com
consistência transacional) e uma superfície HTTP pequena e estável: um conjunto fechado de rotas
REST em JSON, sem renderização de views, sem sessão, sem formulários e sem área administrativa.

Frameworks full-stack (Laravel, Symfony) resolvem muito bem o que este projeto não tem, e em
troca acoplam o código a convenções próprias — Eloquent como Active Record, service container
com resolução mágica, facades estáticas, middlewares proprietários. Em um trabalho cujo critério
de avaliação é a clareza arquitetural, essa camada de mágica é ruído: dificulta demonstrar onde
começa a Clean Architecture e onde termina o framework.

Na Fase 3 a decisão foi reavaliada, porque a aplicação passou a rodar em EKS atrás de um API
Gateway e uma Lambda de autenticação. A pergunta era se a ausência de framework criaria atrito
com a plataforma. Não criou: a integração é HTTP puro e variáveis de ambiente.

## Decisão

Manter a aplicação em **PHP 8.2 puro**, com:

- **Autoload PSR-4** via Composer (única dependência de runtime relevante).
- **Front controller** único em `public/index.php`.
- **Roteador próprio** baseado em expressões regulares com suporte a `{param}`.
- **Injeção de dependência manual e explícita**, montada no bootstrap — sem container mágico.
- Camadas Domain / Application / Infrastructure / Presentation isoladas por interfaces.

Composer segue sendo usado para autoload e ferramentas de desenvolvimento (PHPUnit, PHPStan,
PHP-CS-Fixer); a restrição é a frameworks de aplicação, não a bibliotecas.

## Consequências

**Positivas**

- Todo o caminho de uma requisição é legível de ponta a ponta: `index.php` → `Router` →
  middleware → controller → use case → repositório. Não há resolução implícita.
- Superfície de dependências mínima — menos CVEs herdadas, menos atualizações forçadas, imagem
  de container menor e cold start irrelevante.
- O domínio é 100% agnóstico de infraestrutura: `src/Domain` não conhece PDO, HTTP nem JWT, o
  que permite testá-lo sem nenhum bootstrap.
- A migração para a arquitetura de nuvem da Fase 3 exigiu zero mudança de domínio.

**Negativas**

- Funcionalidades transversais que um framework entrega prontas precisam ser escritas e mantidas
  por nós: validação de request, tratamento global de erro, logging estruturado, correlação de
  requisições, migrations versionadas. Todas foram implementadas na Fase 3 (WS-D).
- Onboarding de quem não conhece o projeto é mais lento: não há documentação externa para as
  convenções internas — ela precisa viver no repositório.
- Risco de reinventar mal o que já existe bem resolvido. Mitigado restringindo o "feito em casa"
  ao que é simples e verificável, e cobrindo com testes (por exemplo, o JWT — ver ADR-002).
- Sem ecossistema de pacotes plugáveis: cada integração nova é código nosso.

## Alternativas consideradas

| Alternativa | Por que não |
|---|---|
| **Laravel** | Eloquent (Active Record) empurra o modelo de domínio para dentro da persistência, exatamente o oposto do que o trabalho quer demonstrar. Peso de runtime alto para uma API de ~30 rotas. |
| **Symfony (full-stack)** | Tecnicamente o mais alinhado a DDD, mas a configuração por atributos/YAML esconde o wiring que queremos evidenciar. Ganho real seria pequeno. |
| **Slim / Lumen (micro-framework)** | Meio-termo defensável: entregaria PSR-7/PSR-15 e roteamento maduro. Descartado porque o roteador próprio já atende e a dependência traria pouco além de uma camada extra para explicar. |
| **Componentes Symfony avulsos (HttpFoundation, Routing)** | Considerado para a Fase 3. Rejeitado por não haver problema real a resolver — o custo de migração não se paga. |
