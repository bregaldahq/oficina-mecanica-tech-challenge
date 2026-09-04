output "cluster_name" {
  description = "EKS cluster name."
  value       = module.eks.cluster_name
}

output "cluster_endpoint" {
  description = "EKS API server endpoint."
  value       = module.eks.cluster_endpoint
}

output "oidc_provider_arn" {
  description = "IRSA OIDC provider ARN."
  value       = module.eks.oidc_provider_arn
}

output "node_security_group_id" {
  description = "Security group attached to the worker nodes."
  value       = module.eks.node_security_group_id
}

output "ecr_repository_url" {
  description = "ECR repository URL for the oficina-api image."
  value       = aws_ecr_repository.api.repository_url
}

output "nlb_arn" {
  description = "Internal NLB ARN, consumed by the API Gateway VPC Link."
  value       = aws_lb.internal.arn
}

output "nlb_listener_arn" {
  description = "TCP :80 listener ARN of the internal NLB."
  value       = aws_lb_listener.http.arn
}

output "nlb_target_group_arn" {
  description = "Target group the AWS Load Balancer Controller registers pod IPs into."
  value       = aws_lb_target_group.api.arn
}

output "app_namespace" {
  description = "Namespace where the oficina-api workload runs in this environment."
  value       = local.app_namespace
}

output "kubeconfig_command" {
  description = "Command that configures kubectl against this cluster."
  value       = "aws eks update-kubeconfig --region ${var.region} --name ${module.eks.cluster_name}"
}
