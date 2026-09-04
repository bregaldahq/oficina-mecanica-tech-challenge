module "eks" {
  source  = "terraform-aws-modules/eks/aws"
  version = "~> 20.0"

  cluster_name    = local.cluster_name
  cluster_version = var.cluster_version

  vpc_id     = local.vpc_id
  subnet_ids = local.node_subnet_ids

  # Public API endpoint (there is no bastion and no NAT), restricted by CIDR.
  cluster_endpoint_public_access       = true
  cluster_endpoint_public_access_cidrs = var.cluster_endpoint_public_access_cidrs
  cluster_endpoint_private_access      = true

  enable_cluster_creator_admin_permissions = true
  authentication_mode                      = "API_AND_CONFIG_MAP"

  cluster_addons = {
    coredns                = {}
    eks-pod-identity-agent = {}
    kube-proxy             = {}
    vpc-cni                = {}
  }

  eks_managed_node_group_defaults = {
    ami_type = "AL2023_x86_64_STANDARD"
  }

  eks_managed_node_groups = {
    default = {
      name = "${local.name_prefix}-ng"

      instance_types = var.node_instance_types
      capacity_type  = var.capacity_type

      min_size     = var.node_min_size
      max_size     = var.node_max_size
      desired_size = var.node_desired_size

      subnet_ids = local.node_subnet_ids

      # ADR-010: no NAT gateway, so nodes need a public IP to pull images and to
      # reach the EKS/ECR/Secrets Manager endpoints.
      network_interfaces = [
        {
          associate_public_ip_address = true
          delete_on_termination       = true
          device_index                = 0
        }
      ]

      block_device_mappings = {
        root = {
          device_name = "/dev/xvda"
          ebs = {
            volume_size           = var.node_disk_size
            volume_type           = "gp3"
            encrypted             = true
            delete_on_termination = true
          }
        }
      }

      # Grants the pods running on this node RDS:3306 access (contract section 2).
      vpc_security_group_ids = [local.db_client_sg_id]

      labels = {
        workload = "oficina-api"
      }

      tags = local.tags
    }
  }

  tags = local.tags
}
