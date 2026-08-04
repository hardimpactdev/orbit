# S3 Commands

S3 commands manage Orbit's object-storage surface. The service exposes an
S3-compatible API across the fleet. The command family is `s3:*`.

The backend technology is SeaweedFS. SeaweedFS is not the product model.

## Domain Rules

These rules govern what the S3 command family owns and what it may not touch.

- The S3 command family owns the `s3:*` command prefix.
- The `s3` role is a private workload role. It runs SeaweedFS in a Docker runtime
  container rendered by Orbit, binds the S3 API only to the node's WireGuard
  address, and receives traffic through router-owned S3 service routes.
- The private fleet endpoint is `https://s3.orbit`. Apps and VPN clients use
  that stable endpoint and never target a concrete S3 node.
- Public S3 hosts, such as `https://s3.example.com`, are published through
  `ingress -> router -> S3 backend pool`.
- Ingress forwards public S3 hosts to router. Ingress must not route directly
  to s3 role nodes.
- Router owns `s3.orbit`, S3 backend pools, S3 upload-compatible proxy
  settings, and private router-to-SeaweedFS routing.
- SeaweedFS runtime uses one canonical node-owned Docker process row. The s3
  role does not own role-local Docker Compose.
- S3 role convergence installs and verifies Docker, applies the canonical
  SeaweedFS process runtime, and starts it before the role assignment becomes
  active. Apply or start failure keeps provisioning incomplete.
- Removing the S3 role removes its live SeaweedFS container before deleting
  the process, tool, and role records. The data path owned by the role remains
  intact unless a separate purge contract explicitly removes it.
- S3 service credentials are service-level SeaweedFS credentials stored on the
  `seaweedfs` tool row. They are visible through `tool:credentials seaweedfs` and
  `s3:credentials`.
- The S3 command family coordinates node, tool, and proxy state. It does not
  own an independent `doctor --family=s3` state family in v1.

## Permissions

S3 commands keep the `s3:*` command family, but v1 authorization uses the
tool-backed permissions for the selected `seaweedfs` tool row on the active s3
node.

- `s3:publish` requires `tool:reconfigure` on the selected active s3 node.
- `s3:unpublish` requires `tool:reconfigure` on the selected active s3 node.
- `s3:credentials` requires `tool:credentials` on the selected active s3 node.

The serving node is the selected active s3 node. Authorization failures use
`authorization_failed` with standard `missing_permission` metadata.

## State Ownership

The S3 command domain coordinates state owned by other families:

- [`node`](../1_node/README.md) owns the `s3` role assignment and its
  `data_path` setting. Role assignment drift is verified and repaired through
  `doctor --family=node`.
- [`tool`](../3_tool/README.md) owns the `seaweedfs` tool row, service
  credentials, and capability updates. SeaweedFS tool-row drift is verified and
  repaired through `doctor --family=tool`.
- [`process`](../7_process/README.md) owns the canonical `seaweedfs` process
  row and its container: start, stop, restart, logs, WireGuard-only bind
  posture, unit-spec drift, and stale-unit cleanup. SeaweedFS runtime drift is
  verified and repaired through `doctor --family=process`.
- [`proxy`](../8_proxy/README.md) owns the proxy route rows, route artifacts,
  TLS material, router private service route, and S3 backend pool. S3 route
  drift is verified and repaired through `doctor --family=proxy`.

The S3 command domain does not own a state family in v1.

## E2E Coverage

Focused S3 E2E coverage lives in `apps/e2e`. Current coverage exercises the
private `s3.orbit` route, credentials output, public ingress publication, and
SeaweedFS WireGuard-only bind posture through prepared topologies. In-memory
gateway command and service tests continue to cover validation, authorization,
route intent, and error envelopes.

## Concepts

These concepts define the vocabulary used by S3 command contracts.

- [S3 Concepts](s3-concepts.md)

## Commands

The `s3:*` family covers public host publication, removal, and credentials.

1. [`orbit s3:publish [host]`](1_s3-publish/s3-publish.md)
2. [`orbit s3:unpublish [host]`](2_s3-unpublish/s3-unpublish.md)
3. [`orbit s3:credentials`](3_s3-credentials/s3-credentials.md)

## Non-Goals

V1 does not include per-app S3 credentials, bucket lifecycle policy, bucket
creation, bucket-level IAM, virtual-hosted bucket routes, wildcard DNS or TLS,
distributed SeaweedFS, high availability, public SeaweedFS console exposure, or
role-local Docker Compose.

## Related

- [`orbit node:*`](../1_node/README.md)
- [`orbit proxy:*`](../8_proxy/README.md)
- [`orbit tool:*`](../3_tool/README.md)
- [`seaweedfs` tool catalog](../3_tool/catalog/seaweedfs.md)
