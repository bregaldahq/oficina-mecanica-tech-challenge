# ---------------------------------------------------------------------------
# B4 - metrics-server. Hard requirement of k8s/hpa.yaml (oficina-api HPA scales
# on Resource/cpu and Resource/memory, which come from metrics.k8s.io).
# ---------------------------------------------------------------------------
resource "helm_release" "metrics_server" {
  name       = "metrics-server"
  repository = "https://kubernetes-sigs.github.io/metrics-server/"
  chart      = "metrics-server"
  version    = var.chart_version_metrics_server
  namespace  = "kube-system"

  values = [yamlencode({
    args = [
      "--kubelet-preferred-address-types=InternalIP,Hostname,ExternalIP",
    ]
    resources = {
      requests = { cpu = "50m", memory = "64Mi" }
      limits   = { memory = "128Mi" }
    }
  })]

  depends_on = [module.eks]
}
