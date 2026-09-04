resource "aws_db_subnet_group" "main" {
  name        = "${local.name_prefix}-db-subnets"
  description = "Private subnets (two distinct AZs) for the RDS instance."
  subnet_ids  = aws_subnet.private[*].id

  tags = { Name = "${local.name_prefix}-db-subnets" }
}

resource "aws_db_parameter_group" "main" {
  name        = "${local.name_prefix}-mysql80"
  family      = "mysql8.0"
  description = "utf8mb4 everywhere and slow-query logging for the oficina-mecanica database."

  parameter {
    name  = "character_set_server"
    value = "utf8mb4"
  }

  parameter {
    name  = "collation_server"
    value = "utf8mb4_unicode_ci"
  }

  parameter {
    name  = "slow_query_log"
    value = "1"
  }

  parameter {
    name  = "long_query_time"
    value = "1"
  }

  lifecycle {
    create_before_destroy = true
  }
}

resource "aws_db_instance" "main" {
  identifier     = "${local.name_prefix}-mysql"
  engine         = "mysql"
  engine_version = var.db_engine_version
  instance_class = var.db_instance_class

  db_name  = var.db_name
  username = var.db_username
  password = random_password.db.result
  port     = 3306

  # gp3 storage, encrypted at rest with the AWS-managed RDS key.
  storage_type          = "gp3"
  allocated_storage     = var.db_allocated_storage
  max_allocated_storage = var.db_max_allocated_storage
  storage_encrypted     = true

  db_subnet_group_name   = aws_db_subnet_group.main.name
  parameter_group_name   = aws_db_parameter_group.main.name
  vpc_security_group_ids = [aws_security_group.db.id]
  publicly_accessible    = var.db_publicly_accessible
  multi_az               = var.db_multi_az

  backup_retention_period = var.db_backup_retention_days
  backup_window           = "04:00-05:00"
  maintenance_window      = "sun:05:30-sun:06:30"
  copy_tags_to_snapshot   = true

  performance_insights_enabled          = true
  performance_insights_retention_period = 7
  enabled_cloudwatch_logs_exports       = ["error", "slowquery"]

  auto_minor_version_upgrade = true
  apply_immediately          = !local.is_prod

  # Guard rails differ by environment on purpose:
  #  - prod cannot be deleted by accident and always leaves a final snapshot;
  #  - hml is disposable, so destroy/apply cycles stay fast and free.
  deletion_protection       = local.is_prod
  skip_final_snapshot       = !local.is_prod
  final_snapshot_identifier = local.is_prod ? "${local.name_prefix}-mysql-final-${formatdate("YYYYMMDDhhmmss", timestamp())}" : null

  tags = { Name = "${local.name_prefix}-mysql" }

  lifecycle {
    ignore_changes = [final_snapshot_identifier]
  }
}
