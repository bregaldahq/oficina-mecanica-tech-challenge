# ---------------------------------------------------------------------------
# The ONLY coupling surface between the four repositories.
# Names come from section 2 of docs/fase-3/CONTRATOS.md and are literal.
# No downstream repo uses terraform_remote_state.
# ---------------------------------------------------------------------------

resource "aws_ssm_parameter" "vpc_id" {
  name  = "/oficina/${var.environment}/network/vpc_id"
  type  = "String"
  value = aws_vpc.main.id
}

resource "aws_ssm_parameter" "private_subnet_ids" {
  name  = "/oficina/${var.environment}/network/private_subnet_ids"
  type  = "StringList"
  value = join(",", aws_subnet.private[*].id)
}

resource "aws_ssm_parameter" "public_subnet_ids" {
  name  = "/oficina/${var.environment}/network/public_subnet_ids"
  type  = "StringList"
  value = join(",", aws_subnet.public[*].id)
}

resource "aws_ssm_parameter" "vpc_cidr" {
  name  = "/oficina/${var.environment}/network/vpc_cidr"
  type  = "String"
  value = aws_vpc.main.cidr_block
}

resource "aws_ssm_parameter" "db_client_sg_id" {
  name        = "/oficina/${var.environment}/db/client_sg_id"
  description = "Attach this SG to EKS nodes and Lambda ENIs to reach RDS:3306."
  type        = "String"
  value       = aws_security_group.db_client.id
}

resource "aws_ssm_parameter" "db_endpoint" {
  name  = "/oficina/${var.environment}/db/endpoint"
  type  = "String"
  value = aws_db_instance.main.address # host only, no port
}

resource "aws_ssm_parameter" "db_port" {
  name  = "/oficina/${var.environment}/db/port"
  type  = "String"
  value = tostring(aws_db_instance.main.port)
}

resource "aws_ssm_parameter" "db_name" {
  name  = "/oficina/${var.environment}/db/name"
  type  = "String"
  value = var.db_name
}

resource "aws_ssm_parameter" "db_secret_arn" {
  name  = "/oficina/${var.environment}/db/secret_arn"
  type  = "String"
  value = aws_secretsmanager_secret.db.arn
}

resource "aws_ssm_parameter" "auth_secret_arn" {
  name  = "/oficina/${var.environment}/auth/secret_arn"
  type  = "String"
  value = aws_secretsmanager_secret.auth.arn
}
