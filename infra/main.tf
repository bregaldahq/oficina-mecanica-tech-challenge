# ---------------------------------------------------------------------------
# Local Kubernetes environment for the Oficina Mecânica API.
#
# Provisions:
#   * a kind (Kubernetes-in-Docker) cluster              -> kind_cluster.this
#   * the application image, loaded into the cluster      -> null_resource.deploy
#   * all workloads from ../k8s (API, MySQL, ConfigMap,   -> null_resource.deploy
#     Secret, Service, HPA) plus the schema migration Job
#
# The database is provisioned in-cluster as the oficina-db StatefulSet (see
# ../k8s/mysql.yaml). For a managed cloud database, swap that StatefulSet for a
# cloud module (e.g. aws_db_instance) and point DB_HOST at its endpoint.
# ---------------------------------------------------------------------------

provider "kind" {}

resource "kind_cluster" "this" {
  name           = var.cluster_name
  node_image     = var.node_image
  wait_for_ready = true
}

resource "null_resource" "deploy" {
  depends_on = [kind_cluster.this]

  # Re-run whenever the image source or any manifest changes.
  triggers = {
    manifests = sha1(join("", [
      for f in fileset("${path.module}/../k8s", "*.yaml") :
      filesha1("${path.module}/../k8s/${f}")
    ]))
    dockerfile = filesha1("${path.module}/../Dockerfile")
  }

  provisioner "local-exec" {
    working_dir = "${path.module}/.."
    interpreter = ["bash", "-c"]
    command     = <<-EOT
      set -euo pipefail
      docker build --target production -t ${var.image} .
      kind load docker-image ${var.image} --name ${var.cluster_name}
      kubectl --context kind-${var.cluster_name} apply -k k8s/
      kubectl --context kind-${var.cluster_name} -n oficina rollout status statefulset/oficina-db --timeout=180s
      kubectl --context kind-${var.cluster_name} -n oficina delete job oficina-migrate --ignore-not-found
      kubectl --context kind-${var.cluster_name} apply -f k8s/migration-job.yaml
      kubectl --context kind-${var.cluster_name} -n oficina wait --for=condition=complete job/oficina-migrate --timeout=180s
    EOT
  }
}
