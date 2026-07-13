output "kube_context" {
  description = "kubectl context for the provisioned cluster."
  value       = "kind-${var.cluster_name}"
}

output "access_hint" {
  description = "How to reach the API locally."
  value       = "kubectl --context kind-${var.cluster_name} -n oficina port-forward svc/oficina-api 8080:80  # then http://localhost:8080/api/health"
}
