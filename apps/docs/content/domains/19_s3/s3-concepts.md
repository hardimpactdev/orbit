# S3 Concepts

This document defines S3-family vocabulary and invariants. It supports the S3
command contracts and does not override the [Architecture](../../architecture.md).

## Identity

These terms define the core vocabulary used across S3 command contracts.

- **S3 command domain:** Command domain that owns the `s3:*` public command
  surface for publishing S3 hosts, unpublishing S3 hosts, and rendering
  service-level credentials.
- **S3 role:** Private workload role that runs one SeaweedFS backend in a Docker
  runtime container rendered by Orbit, binds only to WireGuard, and receives
  traffic through router-owned S3 service routes.
- **SeaweedFS backend:** The S3-compatible SeaweedFS runtime behind the `s3` role.
  Router backend pools target concrete private SeaweedFS backend URLs such as
  `http://storage-1.s3.orbit:8333`.
- **S3 service endpoint:** Stable private HTTPS endpoint `https://s3.orbit`.
  The router owns this endpoint. Apps and VPN clients use it instead of
  concrete S3 nodes.
- **S3 backend pool:** Router-owned ordered list of SeaweedFS backend URLs behind
  `s3.orbit`. V1 creates one backend but stores the pool shape.
- **S3 public host:** Public HTTPS hostname, such as `s3.example.com`,
  published by an operator. Ingress forwards that host to router for S3 traffic.

## Publication

These terms describe how public S3 access is exposed.

- **S3 route publication:** Gateway-owned route convergence that records one
  public S3 host on the selected `seaweedfs` tool row, creates or updates an S3
  public host route on ingress, updates router relay intent, and ensures
  `s3.orbit` and the S3 backend pool exist. Publication must preserve request
  `Host`, forwarded-proto metadata, and upload-safe proxy behavior.
- **S3 service credentials:** SeaweedFS access key and secret stored for the
  service on the `seaweedfs` tool row and returned by `s3:credentials` and
  `tool:credentials seaweedfs`.
- **S3 role data path:** Absolute host path stored on the `s3` role assignment,
  defaulting to `/srv/orbit/s3/data`, mounted into SeaweedFS as `/data`.

## Boundaries

These rules define what S3 commands may and may not change.

- **S3-domain boundaries:** S3 commands coordinate public host publication,
  public host removal, private endpoint metadata, and service-level credential
  rendering. Node role settings remain node-owned, SeaweedFS runtime lifecycle
  remains tool-owned, and proxy route artifacts remain proxy-owned.
- **S3-domain exclusions:** S3 commands do not create buckets, manage bucket
  policy, create per-app credentials, rotate credentials, expose the SeaweedFS
  console, configure wildcard DNS or TLS, build distributed SeaweedFS, or manage
  role-local Docker Compose.
