# `orbit extension:list`

List the built-in Orbit extensions and report local and gateway enablement for
each extension slug.

## Usage

```bash
orbit extension:list [--json]
```

## Arguments and options

- `--json`: Emit the canonical JSON envelope. Human output prints one row per
  extension with local and gateway state.

## Behavior Summary

`extension:list` reads the local node extension state from CLI configuration
and asks the gateway for gateway-side extension state when the gateway is
reachable. If the gateway cannot be reached, the command still reports local
state and marks gateway state as unknown.

## Requirements

- The CLI can read the local Orbit configuration file.
- Gateway state is shown only when the configured gateway is reachable.

## Output Summary

Human output prints one line for each extension. JSON output returns extension
records with local and gateway state. See the renderer contracts for exact
field names and warning shape.

## Examples

```bash
orbit extension:list
orbit extension:list --json
```

## Related

- [`orbit extension:enable`](../2_extension-enable/extension-enable.md)
- [Technical contract](technical/1_extension-list.md)
