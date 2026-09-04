environment     = "hml"
region          = "us-east-1"
cluster_version = "1.30"

# Development environment: SPOT keeps the bill low.
capacity_type       = "SPOT"
node_instance_types = ["t3.small"]
node_desired_size   = 2
node_min_size       = 2
node_max_size       = 4

# Open during the challenge; narrow to the office/CI egress if available.
cluster_endpoint_public_access_cidrs = ["0.0.0.0/0"]

ecr_repository_name       = "oficina-api"
ecr_image_retention_count = 10

newrelic_enabled = true
