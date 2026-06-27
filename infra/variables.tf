variable "cluster_name" {
  description = "Name of the local kind cluster."
  type        = string
  default     = "oficina"
}

variable "image" {
  description = "Application image tag built and loaded into the cluster."
  type        = string
  default     = "oficina-api:local"
}

variable "node_image" {
  description = "kind node image (pins the Kubernetes version)."
  type        = string
  default     = "kindest/node:v1.30.0"
}
