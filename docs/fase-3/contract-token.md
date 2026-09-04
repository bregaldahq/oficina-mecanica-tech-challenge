# Token de contrato

Gerado uma única vez na integração. **Os dois lados (Lambda e aplicação) devem ter um teste que
reproduz exatamente este token.** Se um lado mudar a montagem do JWT, o teste do outro quebra —
é isso que impede o token da Lambda de ser rejeitado pela API em produção.

## Entradas fixas

| Campo | Valor |
|---|---|
| segredo | `contract-test-secret-do-not-use-in-prod` |
| `iat` | `1767225600` |
| `exp` | `1767229200` (iat + 3600) |
| payload (nesta ordem) | `sub`, `role`, `cpf`, `name` |
| `sub` | `11111111-1111-4111-8111-111111111111` |
| `role` | `customer` |
| `cpf` | `52998224725` |
| `name` | `Maria Souza` |
| `iss` | `oficina-mecanica-api` |

## Token esperado

```
eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMTExMTExMS0xMTExLTQxMTEtODExMS0xMTExMTExMTExMTEiLCJyb2xlIjoiY3VzdG9tZXIiLCJjcGYiOiI1Mjk5ODIyNDcyNSIsIm5hbWUiOiJNYXJpYSBTb3V6YSIsImlzcyI6Im9maWNpbmEtbWVjYW5pY2EtYXBpIiwiaWF0IjoxNzY3MjI1NjAwLCJleHAiOjE3NjcyMjkyMDB9.vhZR6_6jD_NdgJvg9yCm4vAkeMUXNCAyY8oE7pr9Vws
```

`52998224725` é um CPF sintético com dígitos verificadores válidos, usado apenas em teste.
