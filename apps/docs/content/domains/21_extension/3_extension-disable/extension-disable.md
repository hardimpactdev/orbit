# `orbit extension:disable`

Disable an Orbit extension locally, or disable that extension on the gateway
when the gateway is the target.

## Usage

```bash
orbit extension:disable <extension> [--json]
orbit extension:disable <extension> --node=gateway [--json]
```

## Arguments and options

- `<extension>`: Built-in extension slug. Current slugs are `cloudflare`,
  `codex`, and `solo`.
- `--node=gateway`: Disable the extension on the gateway instead of this local
  node.
- `--json`: Emit the canonical JSON envelope and do not prompt.

## Behavior Summary

With no `--node`, the command disables the extension on the node where `orbit`
runs. With `--node=gateway`, it disables route execution on the gateway for
that extension after normal gateway authentication and authorization.

## Requirements

- The extension slug must exist in the built-in extension registry.
- Gateway disablement requires a reachable gateway and a grant that permits
  extension state mutation.

## Output Summary

JSON output returns the changed extension state. Disabling a local extension
hides its command family from normal command discovery on the next invocation.

## Examples

```bash
orbit extension:disable cloudflare
orbit extension:disable codex --node=gateway --json
```

## Related

- [`orbit extension:list`](../1_extension-list/extension-list.md)
- [`orbit extension:enable`](../2_extension-enable/extension-enable.md)
- [Technical contract](technical/1_extension-disable.md)
