# WS-D2 — Build, deploy e CI/CD da aplicação

Workstream responsável pela imagem, pelos manifests Kubernetes, pelos workflows do
GitHub Actions e pela coleção Postman.

## Situação por item

| ID | Item | Situação |
|---|---|---|
| D16 | `Dockerfile` em `php:8.2-fpm-bookworm` + agente PHP do New Relic | Pronto — validado com build real |
| D17 | `deploy/base` + overlays `hml`/`prod` em kustomize; `k8s/` e `infra/` removidos | Pronto |
| D18 | Probes em `/api/health` e `/api/ready`; HPA `maxReplicas` 6 → 10 | Pronto |
| D19 | `ExternalSecret` materializando `oficina-secret` | Pronto |
| D20 | `pr.yml` + `deploy.yml` no padrão da seção 9 | Pronto |
| D22 | Coleção Postman em `docs/fase-3/postman/` | Pronto |

## Verificações executadas

| Verificação | Resultado |
|---|---|
| `docker build --target production -t oficina-api:test .` | passou |
| `docker run --rm oficina-api:test php -m \| grep -i newrelic` | `newrelic` |
| `docker run --rm oficina-api:test id` | `uid=33(www-data) gid=33(www-data)` |
| `docker build --target dev` + `composer --version` | passou |
| `php -m` em dev | `newrelic`, `PDO`, `pdo_mysql`, `pdo_sqlite` |
| `kubectl kustomize deploy/overlays/hml` | renderiza |
| `kubectl kustomize deploy/overlays/prod` | renderiza |
| `yaml.safe_load` em `pr.yml` e `deploy.yml` | válido |
| `json.load` nos 3 arquivos do Postman | válido |

## Decisões

### O agente do New Relic e a inércia silenciosa

O agente é instalado pelo pacote oficial `newrelic-php5` do repositório apt da New
Relic (chave `548C16BF`), seguido de `newrelic-install install`. A base é
**bookworm** justamente por isso: o agente é compilado contra glibc e não roda em
musl — era o risco nº 1 do projeto e está fechado com build de verdade.

A extensão é **sempre** carregada (`php -m` mostra `newrelic` em qualquer
ambiente, o que era o critério de aceite). A inércia vem da configuração, não da
ausência da extensão: `docker/php/newrelic.ini` lê tudo de variáveis de ambiente
via interpolação `${VAR}` do parser de ini do PHP, e o `docker-entrypoint.sh`,
quando `NEW_RELIC_LICENSE_KEY` está vazia, exporta:

- `NEW_RELIC_ENABLED=false`
- `NEW_RELIC_MONITOR_MODE=false`
- `NEW_RELIC_DAEMON_DONT_LAUNCH=3` (nunca tentar subir o daemon)

Resultado conferido: sem license key, nada é coletado, nenhum processo extra sobe
e o stdout fica limpo — o que importa porque o stdout é reservado ao log
estruturado JSON da seção 7. Os logs do próprio agente vão para
`/var/log/newrelic/`, e o `newrelic.loglevel` é `error`.

### O uid do `www-data`

No Alpine o `www-data` é uid/gid **82**; no Debian é **33**. Os manifests antigos
tinham `fsGroup: 82` fixo — com a troca de base isso deixaria o `emptyDir`
compartilhado sem permissão de escrita e quebraria tanto o initContainer de cópia
quanto a materialização do `.env`. O `deploy/base/api-deployment.yaml` agora usa
`runAsUser: 33`, `runAsGroup: 33` e `fsGroup: 33`, com `runAsNonRoot: true`.

Como o `runAsNonRoot` do Pod alcança também o sidecar, o Nginx passou da imagem
oficial (cujo master roda como root e faz bind na 80) para
`nginxinc/nginx-unprivileged:1.27-alpine`, escutando em **8080**. O ConfigMap do
Nginx, a porta do container, as probes e o `targetPort` do Service acompanharam.

### Como as migrations chegam ao Job

Escolhido **git clone em initContainer** numa ref imutável:

1. o initContainer `fetch-migrations` (`alpine/git`) clona
   `MIGRATIONS_REPO_URL` na ref `MIGRATIONS_REF` (ambas no ConfigMap
   `oficina-migrations`) e copia `migrations/*.sql` para um `emptyDir`;
2. o container `migrate` roda `php bin/migrate.php` com
   `MIGRATIONS_PATH=/migrations`.

Descartei o ConfigMap gerado no pipeline por três motivos: o limite rígido de
1 MiB por ConfigMap (o conjunto de migrations só cresce), a impossibilidade de
`kubectl kustomize deploy/overlays/*` renderizar offline neste repositório se um
`configMapGenerator` apontasse para arquivos que só existem após um checkout do
repo de banco, e a auditabilidade — com o clone, a ref exata do que foi migrado
fica registrada no manifest aplicado.

O `deploy.yml` fixa `MIGRATIONS_REF` em `main` (produção) ou `develop`
(homologação), com override manual por `workflow_dispatch`.

O Job **não** entra na `kustomization.yaml`: a spec de um Job é imutável e um
`kubectl apply -k` de rotina falharia. O pipeline faz delete + apply a cada deploy.

### Rede de entrada

Removi o `Ingress`. O NLB interno e o target group são do repositório
`oficina-infra-k8s`, e um Ingress criaria um segundo balanceador. O Service ficou
`ClusterIP` e um `TargetGroupBinding` (CRD do AWS Load Balancer Controller)
registra os Pods no target group existente. O ARN é um placeholder substituído
pelo pipeline com o valor lido do SSM.

### License key do New Relic no cluster

Não está no Secrets Manager — é credencial de SaaS, não de ambiente, e a seção 3
dos Contratos fixa exatamente quais chaves os segredos `oficina/<env>/*` contêm.
O pipeline cria o Secret `oficina-newrelic` a partir do segredo de repositório
`NEW_RELIC_LICENSE_KEY`, e o Deployment o consome com `optional: true` — sem ele,
o agente cai no modo inerte descrito acima.

## Divergências e dependências

1. **`/oficina/<env>/nlb/target_group_arn` e `/oficina/<env>/eks/namespace` não
   estão na seção 2 dos Contratos.** O `deploy.yml` lê os dois. A seção 2 publica
   `nlb/arn` e `nlb/listener_arn`, mas o `TargetGroupBinding` precisa do ARN do
   *target group*, que é outro recurso. **Ação necessária:** o repo
   `oficina-infra-k8s` precisa publicar esses dois parâmetros, ou os Contratos
   precisam ser emendados. Enquanto isso o passo de deploy falha na leitura do SSM.

2. **Rotas e runner já convergiram** (reconferido ao fechar a workstream):
   `bin/migrate.php` foi reescrito por outra workstream e lê exatamente
   `MIGRATIONS_PATH` (com fallback para `./migrations`), que é o nome que o Job
   injeta; e `public/index.php` já registra `GET /api/health`, `GET /api/ready` e
   `GET /api/service-orders/me`. Probes, smoke test e coleção Postman estão
   alinhados com o que existe na aplicação — nada pendente aqui.

3. **Se o repositório de migrations for privado**, o clone anônimo do
   initContainer falha. Nesse caso é preciso montar um Secret com uma deploy key
   e trocar a URL para SSH, ou injetar um token em `MIGRATIONS_REPO_URL`.

4. **`DB_HOST`/`DB_PORT`/`DB_DATABASE` saíram do ConfigMap** e passaram a vir só do
   `oficina-secret` (é o que a seção 3 determina). O `envFrom` do Secret vem depois
   do ConfigMap, então mesmo que alguém os reintroduza, o Secret vence.

## Ação humana pendente

- Criar as roles OIDC `oficina-gha-oficina-mecanica-tech-challenge` em cada conta e
  cadastrar `AWS_ROLE_ARN` nos environments `homologacao` e `producao`.
- Cadastrar os segredos de repositório `NEW_RELIC_LICENSE_KEY`,
  `NEW_RELIC_ACCOUNT_ID` e `NEW_RELIC_API_KEY`.
- Criar os environments `homologacao` e `producao` no GitHub (com required
  reviewers em `producao`, se desejado).
- Preencher `baseUrl` e `adminPassword` em
  `docs/fase-3/postman/hml.postman_environment.json` na hora da gravação — o
  `baseUrl` é o valor de `/oficina/hml/apigw/endpoint`.
- Confirmar a URL do repositório de migrations em
  `deploy/base/migrations-configmap.yaml` (hoje
  `https://github.com/bregaldahq/oficina-infra-database.git`). Se o repo for
  privado, o initContainer precisará de credencial — hoje o clone é anônimo.
