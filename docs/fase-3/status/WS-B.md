# WS-B — `repos/oficina-infra-k8s`

Status: **concluído**. `terraform fmt -recursive`, `terraform init -backend=false` e
`terraform validate` passam limpos (rede liberada, módulos e providers baixados da registry).

## Entregas por ID

| ID | Entrega | Arquivos |
|---|---|---|
| B1 | Esqueleto Terraform, backend S3 parametrizável, `envs/{hml,prod}.tfvars`, leitura de VPC/subnets/SG do SSM | `versions.tf`, `providers.tf`, `variables.tf`, `locals.tf`, `data.tf`, `envs/` |
| B2 | EKS 1.30 (`terraform-aws-modules/eks/aws ~> 20.0`), node group 2× `t3.small` min 2 / max 4, `capacity_type` SPOT/ON_DEMAND, nodes em subnet pública com `associate_public_ip_address` | `eks.tf` |
| B3 | IRSA + `aws-load-balancer-controller` via Helm | `addons-alb-controller.tf` |
| B4 | `metrics-server` via Helm | `addons-metrics-server.tf` |
| B5 | `external-secrets` + IRSA restrito aos 2 segredos + `ClusterSecretStore` | `addons-external-secrets.tf` |
| B6 | `nri-bundle` com `kube-state-metrics`, `nri-kube-events` e `newrelic-logging` (Fluent Bit) | `addons-newrelic.tf` |
| B7 | ECR `oficina-api`, scan on push, lifecycle mantendo 10 imagens | `ecr.tf` |
| B8 | NLB interno + target group `ip` + listener `:80` | `nlb.tf` |
| B9 | Namespaces `oficina-hml` / `oficina-prod`; SG cliente de banco anexado aos nodes | `namespaces.tf`, `eks.tf` |
| B10 | Outputs → SSM com os nomes exatos da seção 2 | `ssm.tf`, `outputs.tf` |
| B11 | `pr.yml` / `deploy.yml` no padrão da seção 9, com smoke test `kubectl get nodes` | `.github/workflows/` |
| B12 | `README.md` com diagrama Mermaid próprio e ordem de apply/destroy | `README.md` |

## Decisão sobre o NLB (tensão sinalizada no briefing)

**O Terraform é dono do NLB, do target group e do listener :80.** O AWS Load Balancer
Controller **não** cria load balancer nenhum: ele apenas registra os IPs dos pods como
targets, a partir de um `TargetGroupBinding` que o repositório da aplicação declara
apontando para o target group criado aqui.

Justificativa: `oficina-lambda-auth` aplica **antes** da aplicação e precisa de
`/oficina/<env>/nlb/arn` e `/oficina/<env>/nlb/listener_arn` no momento do seu apply.
Se o NLB nascesse de um `Service type=LoadBalancer`, esses ARNs só existiriam depois do
deploy do app, invertendo a ordem `database → k8s → lambda → app` do contrato.

A alternativa com as annotations `aws-load-balancer-type: external` +
`nlb-target-type: ip` + `scheme: internal` está documentada no README como caminho
não adotado, com o motivo (criaria um segundo NLB e ARN tardio) e o que mudar caso o
time queira migrar.

## Decisões fora dos contratos

1. **Dois parâmetros SSM adicionais** (nenhum nome existente foi alterado):
   - `/oficina/<env>/nlb/target_group_arn` — o repo da aplicação precisa dele para o `TargetGroupBinding`.
   - `/oficina/<env>/eks/namespace` — namespace do ambiente, conveniência para os overlays kustomize.
2. **Provider `gavinbunney/kubectl`** para aplicar o `ClusterSecretStore`. `kubernetes_manifest`
   exige API server acessível em tempo de *plan*, o que quebraria o `plan` do `pr.yml` e a
   criação em um único apply.
3. **Ambos os namespaces** (`oficina-hml` e `oficina-prod`) são criados em todo cluster,
   não só o do ambiente corrente — simplifica o overlay e é inócuo.
4. **`ClusterSecretStore` chamado `oficina-secretsmanager`** — o nome não estava fixado nos
   Contratos; o `ExternalSecret` da aplicação precisa usar exatamente esse nome.
5. **`newrelic_license_key` como variável sensível com default `""`** e um guard
   (`newrelic_enabled && license != ""`): sem a chave, o `nri-bundle` simplesmente não é
   instalado, para que `plan`/`apply` locais não quebrem.
6. **Versões de chart pinadas** em variáveis (`chart_version_*`), para apply reproduzível.
7. **`nri-prometheus`, `newrelic-pixie` e `pixie-chart` desabilitados** e `lowDataMode = true`,
   por custo/ingestão — o escopo pedido (KSM, kube-events, logging) está habilitado.
8. **Regra de SG extra** liberando TCP 1025-65535 no SG dos nodes a partir do CIDR da VPC,
   para o NLB interno alcançar e health-checkar os pods. Health check HTTP em `/api/health`
   (rota pública que não toca no banco, conforme seção 5).
9. **`authentication_mode = "API_AND_CONFIG_MAP"`** e `enable_cluster_creator_admin_permissions`,
   padrão do módulo v20.

## Divergências / riscos encontrados

- **`k8s/hpa.yaml` usa `namespace: oficina`**, que não é nem `oficina-hml` nem `oficina-prod`.
  Conforme a seção 8 dos Contratos, `k8s/` é removido e migra para `deploy/overlays/{hml,prod}`;
  o overlay precisa reescrever o namespace. Isso é do WS do repositório da aplicação — sinalizado aqui.
- **`docs/fase-3/adr/` está vazio**: o ADR-010 (ausência de NAT) foi referenciado apenas por
  comentários no código e no README, sem poder ser lido. Se o ADR contradisser a implementação,
  o `eks.tf` é quem deve ser corrigido.
- **Dependência não verificável**: os parâmetros SSM da seção 2 publicados por
  `oficina-infra-database` ainda não existem (`repos/oficina-infra-database` está vazio).
  Um `terraform plan` real só funciona depois do apply daquele repo.
- As **subnets públicas** precisam ter `map_public_ip_on_launch` ou, no mínimo, rota para o IGW.
  O launch template já força `associate_public_ip_address = true`, então basta a rota — mas se as
  subnets públicas do WS-A não tiverem IGW, os nodes não conseguem entrar no cluster.
- O **NLB fica nas subnets privadas**; o VPC Link do API Gateway também deve ser criado nessas
  mesmas subnets, senão a integração não alcança o LB.

## Depende de ação humana

1. Criar o bucket de state `oficina-tfstate-<sufixo>` e a tabela `oficina-tflock`.
2. Criar a role OIDC `oficina-gha-oficina-infra-k8s` e configurar os secrets de repositório:
   `AWS_ROLE_ARN`, `AWS_ACCOUNT_ID`, `TF_STATE_BUCKET`, `NEW_RELIC_LICENSE_KEY`,
   `NEW_RELIC_ACCOUNT_ID`, `NEW_RELIC_API_KEY` e `NEW_RELIC_INFRA_ENTITY_GUID`
   (este último usado só pelo marcador de deployment; a etapa é não-bloqueante).
3. Restringir `cluster_endpoint_public_access_cidrs` em `envs/prod.tfvars` — hoje está
   `0.0.0.0/0`, aceitável para o desafio, ruim para produção real.
4. Trocar `capacity_type` de `SPOT` para `ON_DEMAND` no ambiente que for avaliado
   (`envs/prod.tfvars` já está em `ON_DEMAND`).
5. Confirmar com o WS da aplicação que o `TargetGroupBinding` e o `ExternalSecret`
   (`secretStoreRef: kind=ClusterSecretStore, name=oficina-secretsmanager`) serão declarados
   no overlay.
6. Rodar `terraform plan` real assim que `oficina-infra-database` estiver aplicado — sem
   credencial AWS o teto de verificação foi `fmt`/`init -backend=false`/`validate`.
