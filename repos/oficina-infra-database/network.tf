data "aws_availability_zones" "available" {
  state = "available"
}

resource "aws_vpc" "main" {
  cidr_block           = var.vpc_cidr
  enable_dns_support   = true
  enable_dns_hostnames = true

  tags = { Name = "${local.name_prefix}-vpc" }
}

resource "aws_internet_gateway" "main" {
  vpc_id = aws_vpc.main.id

  tags = { Name = "${local.name_prefix}-igw" }
}

# ---------------------------------------------------------------------------
# Public subnets.
#
# EKS worker nodes live HERE, not in the private subnets. See ADR-010:
# there is deliberately NO NAT Gateway in this stack. A NAT Gateway costs
# roughly USD 32/month per AZ plus data processing, which dominates the whole
# budget of this challenge. Instead, nodes sit in public subnets with public
# IPs for egress (pulling images from ECR, reaching the AWS APIs), and inbound
# access is closed by security groups, not by subnet placement.
#
# The private subnets below stay private: no route to the IGW, no NAT. They
# hold the RDS instance and the ENIs of the Lambdas, which never need to reach
# the internet -- they reach AWS services through the same VPC or via the
# security-group "badge" pattern.
# ---------------------------------------------------------------------------
resource "aws_subnet" "public" {
  count = length(local.public_subnet_cidrs)

  vpc_id                  = aws_vpc.main.id
  cidr_block              = local.public_subnet_cidrs[count.index]
  availability_zone       = local.azs[count.index]
  map_public_ip_on_launch = true

  tags = {
    Name                     = "${local.name_prefix}-public-${local.azs[count.index]}"
    Tier                     = "public"
    "kubernetes.io/role/elb" = "1"
  }
}

resource "aws_subnet" "private" {
  count = length(local.private_subnet_cidrs)

  vpc_id                  = aws_vpc.main.id
  cidr_block              = local.private_subnet_cidrs[count.index]
  availability_zone       = local.azs[count.index]
  map_public_ip_on_launch = false

  tags = {
    Name                              = "${local.name_prefix}-private-${local.azs[count.index]}"
    Tier                              = "private"
    "kubernetes.io/role/internal-elb" = "1"
  }
}

# The 0.0.0.0/0 -> IGW route here is load-bearing, not decoration
# (CONTRATOS.md, section 2, adendo 3): with no NAT Gateway anywhere in the
# VPC, this is the ONLY path by which EKS nodes reach the cluster API, ECR
# and New Relic. Remove it and the nodes never join the cluster.
resource "aws_route_table" "public" {
  vpc_id = aws_vpc.main.id

  route {
    cidr_block = "0.0.0.0/0"
    gateway_id = aws_internet_gateway.main.id
  }

  tags = { Name = "${local.name_prefix}-rt-public" }
}

# No 0.0.0.0/0 route on purpose (ADR-010: no NAT Gateway).
# Only the implicit local route of the VPC exists here.
resource "aws_route_table" "private" {
  vpc_id = aws_vpc.main.id

  tags = { Name = "${local.name_prefix}-rt-private" }
}

resource "aws_route_table_association" "public" {
  count = length(aws_subnet.public)

  subnet_id      = aws_subnet.public[count.index].id
  route_table_id = aws_route_table.public.id
}

resource "aws_route_table_association" "private" {
  count = length(aws_subnet.private)

  subnet_id      = aws_subnet.private[count.index].id
  route_table_id = aws_route_table.private.id
}
