# Diagrama de sequência — abertura de Ordem de Serviço

Fluxo de `POST /api/service-orders` de ponta a ponta: o token atravessando o authorizer, chegando
ao Pod, a transação do agregado, o evento de domínio e a telemetria que ele produz.

Rota de **admin** (matriz de autorização, seção 5 dos Contratos): criar OS é operação de oficina,
não de cliente.

## Fluxo completo

```mermaid
sequenceDiagram
    autonumber
    actor Op as Atendente (admin)
    participant GW as API Gateway
    participant AZ as Lambda jwt-authorizer
    participant NLB as NLB interno
    participant MW as CorrelationId + AuthMiddleware
    participant UC as CreateServiceOrderUseCase
    participant AG as ServiceOrder (Aggregate Root)
    participant RP as PdoServiceOrderRepository
    participant DB as RDS MySQL
    participant EV as InMemoryEventDispatcher
    participant NR as New Relic

    Op->>GW: POST /api/service-orders<br/>Authorization: Bearer <token admin><br/>{customerId, vehicleId}

    GW->>AZ: invoke REQUEST (cache 300 s)
    AZ-->>GW: {"isAuthorized": true,<br/>"context":{"role":"admin"}}

    GW->>NLB: HTTP_PROXY via VPC Link
    NLB->>MW: encaminha ao IP do Pod

    Note over MW: correlation_id: X-Request-Id ><br/>X-Amzn-Trace-Id > uuid v4 gerado
    MW->>MW: revalida o Bearer localmente<br/>(assinatura + exp + iss)
    MW->>MW: Router::requireRole('admin')

    MW->>UC: execute(CreateServiceOrderInput)
    UC->>AG: ServiceOrder::create(customer, vehicle)
    Note over AG: status inicial RECEIVED<br/>registra ServiceOrderCreatedEvent<br/>na lista interna de eventos
    AG-->>UC: agregado (isNew = true)

    UC->>RP: save(serviceOrder)
    Note over RP,DB: uma transacao para o agregado inteiro (ADR-004)
    RP->>DB: BEGIN
    RP->>DB: INSERT INTO service_orders
    RP->>DB: INSERT service_order_services / service_order_parts
    RP->>DB: UPDATE parts_inventory SET stock_quantity = stock_quantity - :qtd<br/>(CHECK stock_quantity >= 0)

    alt qualquer statement falha
        RP->>DB: ROLLBACK
        RP--xUC: excecao
        UC--xMW: excecao
        MW->>NR: log level=error<br/>exception_class, exception_message, file, line
        MW-->>Op: 500 sem detalhe (APP_DEBUG=false)<br/>+ X-Request-Id
    else sucesso
        RP->>DB: COMMIT
        RP-->>UC: ok

        Note over UC,EV: eventos so DEPOIS do commit —<br/>nao se notifica algo que pode sofrer rollback
        UC->>EV: dispatch(releaseEvents())

        par assinantes do evento
            EV->>DB: StatusHistorySubscriber<br/>INSERT service_order_status_history<br/>(from_status NULL, to_status RECEIVED)
        and
            EV->>NR: NewRelicSubscriber<br/>newrelic_record_custom_event('ServiceOrderCreated',<br/>{orderId, customerId, vehicleId, correlationId, env})
        and
            EV->>EV: StatusChangeEmailNotifier (SMTP)
        end

        MW->>NR: APM: transacao /api/service-orders (POST)<br/>duracao, throughput, distributed trace
        MW->>NR: log JSON stdout via Fluent Bit:<br/>message=request.completed, status=201,<br/>duration_ms, correlation_id, role
        MW-->>Op: 201 {"id":"..."}<br/>+ X-Request-Id
    end
```

## Telemetria produzida por esta requisição

```mermaid
flowchart LR
    req["POST /api/service-orders"] --> apm["Transaction<br/>appName oficina-api-env"]
    req --> log["Log JSON em stdout"]
    req --> ev["ServiceOrderCreatedEvent"]

    apm --> nrT[("New Relic<br/>Transaction")]
    log -->|"Fluent Bit (nri-bundle)"| nrL[("New Relic<br/>Log")]
    ev --> sub1["StatusHistorySubscriber"]
    ev --> sub2["NewRelicSubscriber"]
    ev --> sub3["StatusChangeEmailNotifier"]
    sub1 --> hist[("service_order_status_history")]
    sub2 --> nrE[("New Relic<br/>ServiceOrderCreated")]

    nrT --> dashP["Dashboard Plataforma<br/>p50/p95/p99 · throughput · erro"]
    nrE --> dashN["Dashboard Negocio<br/>volume diario · funil · ticket medio"]
    nrL --> dashP
    hist -.->|"durationSeconds da<br/>proxima transicao"| nrE
```

## Notas

- **`correlation_id` amarra tudo.** Ele é lido de `X-Request-Id`, ou de `X-Amzn-Trace-Id` (que o
  API Gateway injeta), ou gerado como uuid v4. Vai para o log, vai como atributo dos dois custom
  events e volta **sempre** no header `X-Request-Id` da resposta — é o que permite, a partir de um
  erro relatado pelo usuário, achar a linha de log e o evento de negócio correspondentes.
- **Uma transação por agregado** (ADR-004). O Use Case chama `save()` e não sabe que existe
  transação; o repositório abre, persiste OS + serviços + peças + baixa de estoque, e faz commit
  ou rollback.
- **Eventos são despachados depois do commit.** Se fossem despachados antes, um rollback deixaria
  email enviado, histórico gravado e custom event emitido para uma OS que não existe.
- **`ServiceOrderCreated` não tem `durationSeconds`** — é o primeiro estado. O atributo aparece
  em `ServiceOrderStatusChanged`, calculado a partir da linha anterior de
  `service_order_status_history`, e é o que alimenta o painel de tempo médio por status.
- **`newrelic_record_custom_event()` é no-op silencioso** quando a extensão não está carregada
  (ambiente local e suíte de testes). Isso é deliberado: telemetria nunca quebra a aplicação.
- **A criação da OS é a única transição que consome estoque.** Reidratar o agregado usa
  `reconstitute()`, que não dispara evento nem toca em estoque (ADR-005) — por isso um `GET` não
  produz nada deste diagrama.
