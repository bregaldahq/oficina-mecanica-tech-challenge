# ---------------------------------------------------------------------------
# B9 - Application namespaces. Both are created in every cluster so that a
# kustomize overlay can be applied to either without an extra Terraform run.
# ---------------------------------------------------------------------------
resource "kubernetes_namespace" "app" {
  for_each = local.app_namespaces

  metadata {
    name = each.value

    labels = {
      "app.kubernetes.io/part-of" = "oficina-mecanica"
      "oficina.io/environment"    = each.key
    }
  }

  depends_on = [module.eks]
}
