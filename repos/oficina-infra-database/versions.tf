terraform {
  required_version = ">= 1.6.0"

  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "~> 5.60"
    }
    random = {
      source  = "hashicorp/random"
      version = "~> 3.6"
    }
  }

  # Backend parametrizado via -backend-config no `terraform init`.
  # Nada de bucket/key hardcoded: cada ambiente passa seu proprio arquivo.
  #   terraform init -backend-config=backend-hml.hcl
  backend "s3" {}
}
