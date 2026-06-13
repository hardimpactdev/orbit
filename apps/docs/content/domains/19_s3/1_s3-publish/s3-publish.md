# `orbit s3:publish [host]`

[Back to S3 commands.](../README.md)

## Purpose

Publish a public HTTPS hostname for the fleet S3 service.

## Usage

```bash
orbit s3:publish [host] [--node=<node>] [--json]
```

## Description

`s3:publish` creates or converges public S3 routing for one hostname such as
`s3.example.com`.

The command writes ingress route intent for the public host, forwards that host
to router, records the host on the selected `seaweedfs` tool row, and ensures the
router-owned private service route and S3 backend pool exist. Public traffic
flows through `ingress -> router -> S3 backend pool`; ingress must not route
directly to an s3 role node.

## Examples

```bash
orbit s3:publish s3.example.com
orbit s3:publish s3.example.com --node=storage-1
orbit s3:publish s3.example.com --json
```

## Output

Pass `--json` to receive machine-readable output. Human output renders progress
and a summary naming the host, selected S3 node, private endpoint, and public
endpoint.

## Requirements

- The caller can reach the Orbit gateway.
- The caller is authorized for `tool:reconfigure` on the selected active s3
  node.
- At least one active s3 node exists, or `--node` selects an active s3 node.
- An active router exists.
- An active ingress exists.

## Related

Use these contracts to remove a host, inspect credentials, or see the resulting
proxy inventory.

- [`orbit s3:unpublish`](../2_s3-unpublish/s3-unpublish.md)
- [`orbit s3:credentials`](../3_s3-credentials/s3-credentials.md)
- [`orbit proxy:list --filter=s3`](../../8_proxy/1_proxy-list/proxy-list.md)
- [Technical contract](technical/1_s3-publish.md)
