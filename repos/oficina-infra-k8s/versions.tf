terraform {
  required_version = ">= 1.6.0"

  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "~> 5.50"
    }
    kubernetes = {
      source  = "hashicorp/kubernetes"
      version = "~> 2.31"
    }
    helm = {
      source  = "hashicorp/helm"
      version = "~> 2.13"
    }
    kubectl = {
      source  = "gavinbunney/kubectl"
      version = "~> 1.14"
    }
  }

  # Backend is parameterised at init time (see README / workflows):
  #   terraform init \
  #     -backend-config="bucket=oficina-tfstate-<sufixo>" \
  #     -backend-config="key=oficina/oficina-infra-k8s/<env>/terraform.tfstate" \
  #     -backend-config="region=us-east-1" \
  #     -backend-config="dynamodb_table=oficina-tflock"
  backend "s3" {}
}
