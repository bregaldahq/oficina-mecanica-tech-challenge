# Kubernetes manifests

Deploys the Oficina Mecânica API and its MySQL database to any Kubernetes cluster.
Designed and validated on a local **kind** cluster.

## Resources

| File | Kind | Purpose |
|------|------|---------|
| `namespace.yaml` | Namespace | Isolates everything under `oficina` |
| `configmap.yaml` | ConfigMap `oficina-config` | Non-sensitive config (APP_ENV, DB_HOST/PORT/DATABASE, JWT_EXPIRATION) |
| `secret.yaml` | Secret `oficina-secret` | Sensitive values (DB/JWT/admin/webhook). **Placeholders — replace for real use** |
| `nginx-configmap.yaml` | ConfigMap `oficina-nginx` | In-cluster Nginx config (FastCGI to the PHP-FPM sidecar over localhost) |
| `mysql.yaml` | StatefulSet + headless Service | `oficina-db` MySQL 8.0 with a 1Gi PVC |
| `api-deployment.yaml` | Deployment + Service | API pods (init copies app → shared volume; PHP-FPM + Nginx sidecars) |
| `hpa.yaml` | HorizontalPodAutoscaler | Scales the API 2→6 on CPU 70% / memory 80% |
| `ingress.yaml` | Ingress | Optional external access (needs an ingress controller) |
| `migration-job.yaml` | Job | Applies `schema.sql` (runs as MySQL root; idempotent) |
| `kustomization.yaml` | Kustomization | Bundles everything except the Job |

### How config reaches the app

The app reads a `.env` file (`EnvLoader::loadFile`). In the cluster there is no
`.env`, so the image entrypoint (`docker/docker-entrypoint.sh`) **materializes it
from the injected ConfigMap + Secret** environment variables on container start.
Locally (docker-compose) the bind-mounted `.env` already exists, so the entrypoint
is a no-op.

## Apply (local kind)

```bash
# 1. Build and load the image
docker build --target production -t oficina-api:local .
kind create cluster --name oficina
kind load docker-image oficina-api:local --name oficina

# 2. Apply everything
kubectl apply -k k8s/
kubectl -n oficina rollout status statefulset/oficina-db --timeout=180s

# 3. Migrate the database
kubectl apply -f k8s/migration-job.yaml
kubectl -n oficina wait --for=condition=complete job/oficina-migrate --timeout=120s

# 4. Reach the API
kubectl -n oficina port-forward svc/oficina-api 8080:80
curl http://localhost:8080/api/health
```

> Provisioning is automated end-to-end by Terraform — see [`../infra`](../infra).

## Autoscaling (HPA)

The HPA needs the **metrics-server**. Bare kind does not ship it, so targets show
`<unknown>` until it is installed:

```bash
kubectl apply -f https://github.com/kubernetes-sigs/metrics-server/releases/latest/download/components.yaml
# On kind, patch the deployment to skip kubelet TLS verification:
kubectl -n kube-system patch deployment metrics-server --type=json \
  -p='[{"op":"add","path":"/spec/template/spec/containers/0/args/-","value":"--kubelet-insecure-tls"}]'
```

Generate load (e.g. `hey`/`ab` against `/api/health`, or many service-order
requests) and watch it scale: `kubectl -n oficina get hpa -w`.

## Production note

`secret.yaml` ships placeholder credentials so a fresh cluster comes up. For real
environments, do not commit secrets — create them out-of-band or via the CI/CD
pipeline, and remove the `ports` exposure / tighten the Ingress.
