# `orbit extension:enable`

Enable an Orbit extension locally, or enable that extension on the gateway when
the gateway is the target.

## Usage

```bash
orbit extension:enable <extension> [--gateway] [--json]
orbit extension:enable <extension> --node=gateway [--json]
```

## Arguments and options

- `<extension>`: Built-in extension slug. Current slugs are `cloudflare`,
  `codex`, and `solo`.
- `--gateway`: After local enablement, also enable the extension on the gateway
  when gateway state is disabled.
- `--node=gateway`: Enable the extension on the gateway instead of this local
  node.
- `--json`: Emit the canonical JSON envelope and do not prompt.

## Behavior Summary

With no `--node`, the command enables the extension on the node where `orbit`
runs. If the gateway reports that the same extension is disabled, an
interactive caller can confirm gateway enablement; non-interactive callers must
pass `--gateway`.

With `--node=gateway`, the command enables the gateway state only.

## Requirements

- The extension slug must exist in the built-in extension registry.
- Gateway enablement requires a reachable gateway and a grant that permits
  extension state mutation.

## Output Summary

JSON output returns the changed extension state. Non-interactive local
enablement with a disabled gateway returns the canonical gateway-enable-required
failure unless `--gateway` is present.

## Examples

```bash
orbit extension:enable cloudflare
orbit extension:enable codex --gateway
orbit extension:enable solo --node=gateway --json
```

## Related

- [`orbit extension:list`](../1_extension-list/extension-list.md)
- [`orbit extension:disable`](../3_extension-disable/extension-disable.md)
- [Technical contract](technical/1_extension-enable.md)
