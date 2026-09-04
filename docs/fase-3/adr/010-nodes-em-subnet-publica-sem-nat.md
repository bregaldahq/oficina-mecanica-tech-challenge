# ADR-010 · Nodes do EKS em subnet pública, sem NAT Gateway

## Status

Aceita (Fase 3) — **com ressalva explícita**: é uma decisão de custo em contexto acadêmico, não
uma recomendação de arquitetura para produção real.

## Contexto

Os nodes do EKS precisam de saída para a internet, sempre. Não é opcional:

- registrar-se no plano de controle do EKS (endpoint público);
- baixar imagens do ECR (e do `registry.k8s.io`, e do Docker Hub para os charts de add-on);
- enviar telemetria para o New Relic (`*.newrelic.com`, HTTPS);
- resolver e alcançar o Secrets Manager e o SSM (a menos que se criem VPC endpoints).

Há três formas de dar essa saída:

| Forma | Custo mensal aproximado (us-east-1) |
|---|---|
| NAT Gateway (1 por AZ, 2 AZs) | ~US$ 65 + tráfego |
| NAT Gateway único (1 AZ, sem HA) | ~US$ 33 + tráfego |
| Nodes em subnet pública com IP público | **US$ 0** |
| VPC endpoints (ECR API, ECR DKR, S3, STS, Secrets Manager, SSM, Logs) | ~US$ 7/endpoint/AZ ≈ US$ 90 |

O NAT Gateway é o item de infraestrutura **mais caro deste projeto depois do EKS** — mais caro que
o próprio RDS `db.t4g.micro`. Em um Tech Challenge pago do bolso do aluno, num ambiente que fica
ligado por semanas, ~US$ 33/mês (ou 65, se com HA) é dinheiro real por uma postura de segurança que
não protege nenhum dado real.

## Decisão

Provisionar a VPC `10.20.0.0/16` com **2 subnets públicas e 2 subnets privadas** em AZs distintas,
**sem NAT Gateway**, e:

- **Nodes do EKS**: nas **subnets públicas**, com `map_public_ip_on_launch = true`. As subnets
  públicas têm rota `0.0.0.0/0` → Internet Gateway (sem ela, os nodes nem entram no cluster).
- **RDS MySQL**: nas **subnets privadas**, sem rota para a internet, `publicly_accessible = false`.
- **NLB interno** e **VPC Link** do API Gateway: nas **subnets privadas**.
- **Lambdas**: nas subnets privadas, com o SG cliente de banco anexado.

As proteções que **substituem** o isolamento de rede dos nodes são de camada de security group e
de exposição de serviço:

1. O SG dos nodes **não tem nenhuma regra de ingress vinda de `0.0.0.0/0`**. Ter IP público não
   significa aceitar conexão: sem ingress liberado, o node é inalcançável de fora.
2. O tráfego da aplicação entra **exclusivamente** pelo API Gateway → VPC Link → **NLB interno**.
   O NLB é `internal`, não tem endereço público, e o Service da aplicação não é `LoadBalancer`
   público nem `NodePort` exposto.
3. O RDS aceita `3306` **somente** do SG `oficina-<env>-db-client`, anexado aos nodes e às
   Lambdas — não de CIDR.
4. Os segredos vêm do Secrets Manager por IRSA/External Secrets, nunca de variável em manifesto.

## O que se perde — sendo honesto

Esta é a parte que não deve ser suavizada. Comparado a nodes em subnet privada com NAT:

- **A superfície de ataque deixa de ser zero e passa a ser "zero enquanto a configuração estiver
  certa".** Com node em subnet privada, mesmo um security group aberto por engano não torna o
  node alcançável da internet — a rota simplesmente não existe. Com node em subnet pública, **uma
  única regra de ingress errada** (um `0.0.0.0/0` colocado para "testar rapidinho", um add-on que
  cria um `Service` do tipo `LoadBalancer`, um `NodePort` exposto) expõe o node imediatamente.
  Trocamos uma proteção estrutural por uma proteção configurável.
- **Perde-se uma camada de defesa em profundidade.** Se um Pod for comprometido, um node privado
  ainda obriga o atacante a sair por um caminho controlado e observável; num node público, o
  tráfego de saída e o endereço público facilitam exfiltração e canal de comando e controle.
- **Perde-se controle e observabilidade da saída.** Com NAT, todo egress sai por IPs fixos e
  conhecidos, que podem ser allowlistados por terceiros e monitorados num ponto só. Sem NAT, cada
  node tem seu próprio IP público, efêmero, que muda a cada substituição de instância.
- **Descumpre-se a recomendação explícita da AWS** (EKS Best Practices Guide) e de qualquer
  baseline de conformidade — CIS AWS Foundations, PCI-DSS, e provavelmente a política de segurança
  de qualquer empresa. Um auditor apontaria isso como achado.
- **Não é aceitável em produção com dado real de cliente.** O sistema manipula CPF e nome —
  dado pessoal sob a LGPD. Num ambiente que não fosse acadêmico e sem dado real, esta decisão
  estaria errada.

O que **não** se perde: o banco continua isolado, o cluster continua sem porta aberta e o único
caminho de entrada continua sendo o gateway. O dano de uma falha de configuração seria grave, mas
o desenho de entrada não depende do node ser público.

## Consequências

**Positivas**

- Economia direta de ~US$ 33 a US$ 65 por mês por ambiente, sem contar o custo por GB processado.
- Sem NAT Gateway, não há gargalo nem custo de tráfego para os pulls de imagem e para a telemetria
  contínua do New Relic.
- Arquitetura de rede mais simples para explicar e para depurar: menos um componente, menos uma
  tabela de rotas.
- A destruição e recriação do cluster fica mais rápida (NAT Gateway leva minutos para provisionar
  e para deletar).

**Negativas**

- Todas as listadas na seção anterior. A principal é a mudança de natureza da proteção: de
  estrutural para configurável.
- A configuração passa a exigir vigilância ativa: qualquer PR que mexa em security group dos nodes
  ou crie `Service` do tipo `LoadBalancer` merece revisão dedicada.
- O ambiente **não é promovível a produção real como está** — migrar exigiria NAT (ou VPC
  endpoints) e mover o node group para as subnets privadas.
- Postura inconsistente entre camadas: banco privado, node público. Alguém que leia só o diagrama
  pode se confundir.

**Mitigações adotadas e a adotar**

- Adotadas: SG dos nodes sem ingress público; NLB interno; RDS restrito por SG; segredos fora dos
  manifestos.
- A adotar antes de qualquer uso real (registrado em `PENDENCIAS.md`): mover o node group para
  subnets privadas + NAT Gateway (ou VPC endpoints para ECR/S3/STS/Secrets Manager/Logs, que
  eliminam a maior parte da necessidade de egress e podem sair mais baratos que o NAT quando são
  poucos); habilitar VPC Flow Logs; restringir o endpoint público da API do EKS por CIDR.

## Alternativas consideradas

| Alternativa | Custo | Avaliação | Veredito |
|---|---|---|---|
| **Nodes privados + NAT Gateway por AZ (2)** | ~US$ 65/mês + tráfego | Postura correta e resiliente a falha de AZ. É o alvo para produção. | Rejeitada nesta fase por custo |
| **Nodes privados + NAT Gateway único** | ~US$ 33/mês + tráfego | Metade do custo, mas cria dependência de uma AZ para todo o egress — se a AZ do NAT cair, o cluster inteiro perde saída. Segurança boa, resiliência pior. | Rejeitada por custo |
| **Nodes privados + VPC endpoints (sem NAT)** | ~US$ 90/mês | Tecnicamente elegante: elimina o egress para ECR, S3, STS, Secrets Manager e Logs. Mas **não resolve o New Relic**, que é internet pública — sobraria a necessidade de NAT assim mesmo. Mais caro que a opção que pretendia substituir. | Rejeitada |
| **NAT instance (t4g.nano com `fck-nat`)** | ~US$ 3/mês | A opção mais interessante do ponto de vista custo-benefício: preserva nodes privados por um custo quase nulo. Rejeitada porque é um componente autogerido no caminho crítico de rede (patching, monitoração, ponto único de falha) e adiciona código de infraestrutura que não é o objeto de avaliação do desafio. **É a primeira alternativa a revisitar** se a postura de segurança precisar melhorar sem estourar o orçamento. | Rejeitada — reavaliar |
| **Fargate no lugar de node group** | variável | Elimina a gestão de node, mas exige NAT ou endpoints do mesmo jeito para os pods privados, e o EKS Fargate tem cold start e restrições (sem DaemonSet — o que quebra o agente de infraestrutura do New Relic). | Rejeitada |
