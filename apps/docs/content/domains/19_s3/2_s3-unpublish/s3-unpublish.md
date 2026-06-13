# `orbit s3:unpublish [host]`

[Back to S3 commands.](../README.md)

## Purpose

Remove one public HTTPS hostname from the fleet S3 service.

## Usage

```bash
orbit s3:unpublish [host] [--node=<node>] [--force] [--json]
```

## Description

`s3:unpublish` removes one public S3 host route and removes that host from the
selected `seaweedfs` tool row configuration.

The command does not remove the private `https://s3.orbit` endpoint, the S3
backend pool, SeaweedFS credentials, buckets, object data, or the s3 role's
`data_path`.

## Examples

```bash
orbit s3:unpublish s3.example.com
orbit s3:unpublish s3.example.com --node=storage-1 --force
orbit s3:unpublish s3.example.com --force --json
```

## Output

Pass `--json` to receive machine-readable output. Human output renders progress
and a summary naming the removed host and selected S3 node.

## Requirements

- The caller can reach the Orbit gateway.
- The caller is authorized for `tool:reconfigure` on the selected active s3
  node.
- At least one active s3 node exists, or `--node` selects an active s3 node.
- An active router exists.
- Non-interactive mode requires `--force`.

## Related

Use these contracts to publish a host, inspect credentials, or see the
resulting proxy inventory.

- [`orbit s3:publish`](../1_s3-publish/s3-publish.md)
- [`orbit s3:credentials`](../3_s3-credentials/s3-credentials.md)
- [`orbit proxy:list --filter=s3`](../../8_proxy/1_proxy-list/proxy-list.md)
- [Technical contract](technical/1_s3-unpublish.md)
