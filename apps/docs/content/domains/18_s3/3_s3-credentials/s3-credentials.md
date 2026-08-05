# `orbit s3:credentials`

[Back to S3 commands.](../README.md)

## Purpose

Show service-level credentials and endpoint metadata for the fleet S3 service.

## Usage

```bash
orbit s3:credentials [--node=<node>] [--json]
```

## Description

`s3:credentials` reads the service-level SeaweedFS credentials stored on the
selected `seaweedfs` tool row and renders endpoint metadata for clients.

The command returns the private endpoint `https://s3.orbit`, any public S3
hosts published for the selected node, the generated access key and secret
access key, and path-style endpoint guidance.

## Examples

```bash
orbit s3:credentials
orbit s3:credentials --node=storage-1
orbit s3:credentials --json
```

## Output

Pass `--json` to receive machine-readable output. Human output renders the
connection fields without probing live SeaweedFS health.

## Requirements

- The caller can reach the Orbit gateway.
- The caller is authorized for `tool:credentials` on the selected active s3
  node.
- At least one active s3 node exists, or `--node` selects an active s3 node.
- An active router exists.

## Related

- [`orbit s3:publish`](../1_s3-publish/s3-publish.md)
- [`orbit s3:unpublish`](../2_s3-unpublish/s3-unpublish.md)
- [`orbit tool:credentials seaweedfs`](../../3_tool/10_tool-credentials/tool-credentials.md)
- [Technical contract](technical/1_s3-credentials.md)
