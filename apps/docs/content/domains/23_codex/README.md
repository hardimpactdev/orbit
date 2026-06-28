# Codex Commands

Codex commands manage Orbit's Codex App integration. The current command family
registers gateway-owned Orbit apps in Codex App on eligible operator nodes.

## State Ownership

The Codex command domain does not own a state family. The Codex extension owns
Codex App project registration. App records remain owned by the app family, and
Codex App configuration is applied on the selected operator node through the
gateway.

[`doctor --family=app`](../5_app/app-doctor.md) owns app registry and app
runtime health. [`doctor --family=tool`](../3_tool/tool-doctor.md) owns
Codex App tool capability readiness. Codex commands do not create codex-family
doctor issues.

## Domain Rules

These rules constrain all Codex commands.

- Codex commands are optional built-in extension commands. They appear in normal
  local command discovery only after `orbit extension:enable codex` has enabled
  local state on the caller node.
- Gateway Codex API routes run only when the gateway-side `codex` extension is
  enabled.
- `codex:app` is the command-family spelling. `app:codex` is removed from the
  public command surface.
- The command edits only Codex App project configuration on eligible macOS
  target nodes. It does not change app runtime configuration or the app's Agent
  IDE adapter.

## Commands

The Codex family has one command in this slice.

1. [`orbit codex:app add|remove|list [app]`](1_codex-app/codex-app.md)

## Related

- [`orbit extension:enable codex`](../22_extension/2_extension-enable/extension-enable.md)
- [`orbit app:*`](../5_app/README.md)
- [`codex-app` tool catalog entry](../3_tool/catalog/codex-app.md)
