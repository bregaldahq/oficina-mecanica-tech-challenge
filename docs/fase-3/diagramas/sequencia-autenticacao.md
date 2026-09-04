# Diagrama de sequência — autenticação do cliente por CPF

Fluxo de `POST /auth/cpf`, incluindo **todos os ramos de erro** da seção 5 dos Contratos.

Esta rota **não passa pelo authorizer** — é justamente onde o cliente obtém o token. Ela é servida
diretamente pela Lambda `auth-cpf`, que fica nas subnets privadas com o SG cliente de banco
anexado, e por isso alcança o RDS.

## Caminho feliz e ramos de erro

```mermaid
sequenceDiagram
    autonumber
    actor C as Cliente
    participant GW as API Gateway HTTP API
    participant L as Lambda auth-cpf
    participant SM as Secrets Manager
    participant DB as RDS MySQL
    participant NR as New Relic

    C->>GW: POST /auth/cpf<br/>{"cpf":"12345678909"}
    Note over GW: rota sem authorizer<br/>throttling por rota aplicado
    GW->>L: invoke (payload 2.0)

    alt segredo ainda nao esta em cache
        L->>SM: GetSecretValue oficina/env/auth
        SM-->>L: JWT_SECRET, JWT_EXPIRATION
        Note over L: cache em variavel estatica<br/>reaproveitado entre invocacoes
    end

    alt campo cpf ausente ou vazio
        L-->>GW: 400 {"error":"O campo cpf e obrigatorio."}
        GW-->>C: 400
    else CPF com digito verificador invalido ou CNPJ (14 digitos)
        Note over L: Cpf::isValid() — mesma regra<br/>do VO Document da aplicacao
        L-->>GW: 400 {"error":"CPF invalido."}
        GW-->>C: 400
    else CPF valido
        L->>DB: SELECT id, name, status FROM customers<br/>WHERE document = ?

        alt falha de conexao ou erro de SQL
            DB--xL: excecao
            L->>NR: noticeError + log level=error
            L-->>GW: 500 {"error":"Erro interno."}
            GW-->>C: 500
        else nenhuma linha
            DB-->>L: 0 linhas
            L-->>GW: 404 {"error":"Cliente nao encontrado."}
            GW-->>C: 404
        else status INACTIVE ou BLOCKED
            DB-->>L: {id, name, status}
            L-->>GW: 403 {"error":"Cliente inativo. Procure a oficina."}
            GW-->>C: 403
        else status ACTIVE
            DB-->>L: {id, name, status: ACTIVE}
            Note over L: JwtProvider::generate()<br/>HS256 · iss=oficina-mecanica-api<br/>sub=customer_id · role=customer<br/>claims cpf e name primeiro,<br/>depois iss, iat, exp
            L->>NR: custom event + duracao da invocacao
            L-->>GW: 200 {"token":"...","expiresIn":3600,<br/>"customer":{"id":"...","name":"..."}}
            GW-->>C: 200
        end
    end
```

## Uso do token obtido

```mermaid
sequenceDiagram
    autonumber
    actor C as Cliente
    participant GW as API Gateway
    participant A as Lambda jwt-authorizer
    participant NLB as NLB interno
    participant P as Pod oficina-api
    participant DB as RDS MySQL

    C->>GW: GET /api/service-orders/me<br/>Authorization: Bearer <token>

    alt resultado ja em cache (mesmo header, < 300 s)
        Note over GW: authorizer_result_ttl_in_seconds = 300<br/>a Lambda NAO e invocada
    else primeira vez ou cache expirado
        GW->>A: invoke REQUEST (payload 2.0)<br/>identity_sources = $request.header.Authorization
        alt header ausente ou fora do formato Bearer
            A-->>GW: {"isAuthorized": false}
            GW-->>C: 401
        else assinatura invalida ou exp vencido
            Note over A: hash_hmac + hash_equals<br/>mesmo JWT_SECRET da aplicacao
            A-->>GW: {"isAuthorized": false}
            GW-->>C: 401
        else token valido
            A-->>GW: {"isAuthorized": true,<br/>"context":{"customerId":"...","role":"customer"}}
        end
    end

    GW->>NLB: HTTP_PROXY via VPC Link
    NLB->>P: encaminha ao IP do Pod (target_type = ip)

    Note over P: defesa em profundidade —<br/>AuthMiddleware revalida o Bearer do zero.<br/>Nao confia em header injetado pelo gateway.

    alt revalidacao local falha
        P-->>C: 401
    else role nao autorizada para a rota
        P-->>C: 403
    else autorizado
        P->>DB: SELECT ... WHERE customer_id = :sub
        DB-->>P: apenas as OS do proprio cliente
        P-->>C: 200 + header X-Request-Id
    end
```

## Notas

- **`POST /api/auth/login` (admin)** passa pelo `ANY /api/{proxy+}`, ou seja, atravessa o
  authorizer — mas o `jwt-authorizer` **libera essa rota sem token**, checando `$request.path`. É
  o único caso de liberação por caminho, e existe porque é onde o admin obtém o dele.
- **404 e 403 são distinguíveis** em `POST /auth/cpf`. É um vazamento de enumeração aceito
  conscientemente (RFC-003): sem ele, o cliente bloqueado não saberia que precisa procurar a
  oficina. Já em `GET /api/service-orders/{id}`, o contrato manda **404 e não 403** quando o
  cliente não é o dono — ali a existência da OS é informação sensível.
- **O cache de 300 s do authorizer** significa que um cliente que passe a `BLOCKED` continua
  autorizado na borda por até 5 minutos. A revalidação no Pod é o que aplica a regra atual —
  não é redundância cerimonial.
- **CPF é identificação, não autenticação forte.** A mitigação é de autorização: o token de
  `role=customer` só dá leitura do que é dele, nunca escrita. Ver RFC-003, questão em aberto 1.
