variable "aws_region" {
  description = "AWS region for every resource in this stack."
  type        = string
  default     = "us-east-1"
}

variable "environment" {
  description = "Environment name. Drives every resource name and every SSM path."
  type        = string

  validation {
    condition     = contains(["hml", "prod"], var.environment)
    error_message = "environment must be either \"hml\" or \"prod\"."
  }
}

variable "vpc_cidr" {
  description = "CIDR block of the foundation VPC."
  type        = string
  default     = "10.20.0.0/16"
}

variable "db_name" {
  description = "Logical database created inside the RDS instance."
  type        = string
  default     = "oficina_mecanica"
}

variable "db_username" {
  description = "Master username of the RDS instance."
  type        = string
  default     = "oficina_user"
}

variable "db_instance_class" {
  description = "RDS instance class. t4g.micro keeps the challenge inside the free/low tier."
  type        = string
  default     = "db.t4g.micro"
}

variable "db_engine_version" {
  description = "MySQL engine version."
  type        = string
  default     = "8.0"
}

variable "db_allocated_storage" {
  description = "Allocated storage in GiB (gp3)."
  type        = number
  default     = 20
}

variable "db_max_allocated_storage" {
  description = "Upper bound for RDS storage autoscaling in GiB."
  type        = number
  default     = 100
}

variable "db_backup_retention_days" {
  description = "Automated backup retention window in days."
  type        = number
  default     = 7
}

variable "db_multi_az" {
  description = "Whether to run the RDS instance in Multi-AZ. Off by default for cost."
  type        = bool
  default     = false
}

variable "db_publicly_accessible" {
  description = "Never true in a reviewed environment. Kept as a variable only for emergency debugging."
  type        = bool
  default     = false
}

variable "admin_username" {
  description = "Admin username stored in the auth secret and used as the JWT `sub` for role=admin."
  type        = string
  default     = "admin"
}

variable "jwt_expiration_seconds" {
  description = "JWT lifetime, in seconds, stored as a string inside the auth secret."
  type        = string
  default     = "3600"
}

variable "secret_recovery_window_days" {
  description = "Secrets Manager recovery window. 0 in hml so a destroy/apply cycle can reuse the name."
  type        = number
  default     = 0
}
