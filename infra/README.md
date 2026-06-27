# Infrastructure as Code (Terraform)

Provisions the full local Kubernetes environment for the Oficina Mecânica API with
a single `terraform apply`.

## What it creates

| Resource | Description |
|----------|-------------|
| `kind_cluster.this` | A local Kubernetes cluster via **kind** (Kubernetes-in-Docker), version pinned by `node_image` |
| `null_resource.deploy` | Builds the production image, loads it into the cluster, applies all `../k8s` manifests (API, **database**, ConfigMap, Secret, Service, HPA) and runs the schema-migration Job |

The database is provisioned **in-cluster** as the `oficina-db` MySQL StatefulSet
(`../k8s/mysql.yaml`) with a persistent volume. For a managed cloud database,
replace that StatefulSet with a cloud module (e.g. `aws_db_instance` /
`google_sql_database_instance`) and set `DB_HOST` in the ConfigMap to its endpoint.

## Prerequisites

- Docker, `kubectl`, and `kind` on `PATH`
- Terraform >= 1.5

## Apply

```bash
cd infra
terraform init
terraform plan
terraform apply        # creates the cluster, builds/loads the image, deploys, migrates

# Reach the API (see the `access_hint` output)
kubectl --context kind-oficina -n oficina port-forward svc/oficina-api 8080:80
curl http://localhost:8080/api/health
```

## Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `cluster_name` | `oficina` | kind cluster name |
| `image` | `oficina-api:local` | Image tag built and loaded |
| `node_image` | `kindest/node:v1.30.0` | Pins the Kubernetes version |

## Destroy

```bash
terraform destroy      # deletes the kind cluster and all workloads/data
```

## Re-deploy on change

`null_resource.deploy` is keyed to the hash of the `../k8s` manifests and the
`Dockerfile`; changing either triggers a rebuild + re-apply on the next
`terraform apply`.
