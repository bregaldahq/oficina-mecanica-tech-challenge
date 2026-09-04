output "vpc_id" {
  description = "Foundation VPC id."
  value       = aws_vpc.main.id
}

output "vpc_cidr" {
  description = "Foundation VPC CIDR."
  value       = aws_vpc.main.cidr_block
}

output "public_subnet_ids" {
  description = "Public subnets (EKS nodes live here -- no NAT, see ADR-010)."
  value       = aws_subnet.public[*].id
}

output "private_subnet_ids" {
  description = "Private subnets (RDS and Lambda ENIs)."
  value       = aws_subnet.private[*].id
}

output "db_security_group_id" {
  description = "SG attached to the RDS instance."
  value       = aws_security_group.db.id
}

output "db_client_security_group_id" {
  description = "Badge SG that grants access to RDS:3306."
  value       = aws_security_group.db_client.id
}

output "db_endpoint" {
  description = "RDS host, without port."
  value       = aws_db_instance.main.address
}

output "db_port" {
  description = "RDS port."
  value       = aws_db_instance.main.port
}

output "db_name" {
  description = "Logical database name."
  value       = var.db_name
}

output "db_secret_arn" {
  description = "ARN of the oficina/<env>/db secret."
  value       = aws_secretsmanager_secret.db.arn
}

output "auth_secret_arn" {
  description = "ARN of the oficina/<env>/auth secret."
  value       = aws_secretsmanager_secret.auth.arn
}
