environment     = "prod"
region          = "us-east-1"
cluster_version = "1.30"

# Graded delivery runs on ON_DEMAND so a spot reclaim cannot break the demo.
capacity_type       = "ON_DEMAND"
node_instance_types = ["t3.small"]
node_desired_size   = 2
node_min_size       = 2
node_max_size       = 4

cluster_endpoint_public_access_cidrs = ["0.0.0.0/0"]

ecr_repository_name       = "oficina-api"
ecr_image_retention_count = 10

newrelic_enabled = true
