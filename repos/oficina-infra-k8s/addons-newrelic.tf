# ---------------------------------------------------------------------------
# B6 - New Relic nri-bundle: infrastructure agent, kube-state-metrics,
# nri-kube-events and newrelic-logging (Fluent Bit) shipping the structured
# stdout logs described in CONTRATOS.md section 7.
# ---------------------------------------------------------------------------
locals {
  newrelic_install = var.newrelic_enabled && var.newrelic_license_key != ""
}

resource "helm_release" "nri_bundle" {
  count = local.newrelic_install ? 1 : 0

  name             = "nri-bundle"
  repository       = "https://helm-charts.newrelic.com"
  chart            = "nri-bundle"
  version          = var.chart_version_nri_bundle
  namespace        = "newrelic"
  create_namespace = true

  values = [yamlencode({
    global = {
      licenseKey  = var.newrelic_license_key
      cluster     = module.eks.cluster_name
      lowDataMode = true
    }

    "newrelic-infrastructure" = {
      enabled    = true
      privileged = true
    }

    # Cluster-level metrics feeding the New Relic Kubernetes dashboards.
    "kube-state-metrics" = {
      enabled = true
    }

    # Kubernetes events (pod evictions, OOMKills, HPA scaling decisions).
    "nri-kube-events" = {
      enabled = true
    }

    # Fluent Bit DaemonSet: one JSON line per request straight to New Relic Logs.
    "newrelic-logging" = {
      enabled = true
    }

    "nri-metadata-injection" = { enabled = true }
    "nri-prometheus"         = { enabled = false }
    "newrelic-pixie"         = { enabled = false }
    "pixie-chart"            = { enabled = false }
  })]

  depends_on = [module.eks]
}
