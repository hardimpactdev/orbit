# Extension Commands

Extension commands manage whether optional built-in Orbit command and gateway
API families are enabled. Extensions are built into this Orbit release; this
domain does not define downloadable or third-party extension installation.

## State Ownership

The extension command domain does not own a state family. Extension state has
two scopes.

- Local node extension state is stored in the caller's CLI configuration and
  decides whether extension command families appear on that node's normal
  `orbit list` output.
- Gateway extension state is stored in the gateway database and decides whether
  gateway routes for that extension may run after identity and grant checks.

The scopes are intentionally separate. A workload node can have Codex App or
Solo installed without enabling that extension's command family locally unless
that node itself needs to run those commands.

[`doctor --family=node`](../1_node/node-doctor.md) owns node identity, local
configuration reachability, and gateway reachability. Extension commands do not
create extension-family doctor issues.

## Domain Rules

These rules constrain all extension commands.

- Extension slugs are registry-defined. The built-in slugs in this release are
  `cloudflare`, `codex`, and `solo`.
- `extension:enable <slug>` with no `--node` enables that extension locally on
  the node where the command runs.
- `extension:enable <slug> --node=gateway` enables the gateway-side state for
  that extension.
- When local enablement succeeds but gateway state is disabled, interactive
  callers may choose to enable the gateway as part of the same command.
  Non-interactive and JSON callers must pass `--gateway` or receive
  `extension_gateway_enable_required`.
- `--node=gateway` is the only remote node target in this slice. Workload-node
  remote enablement is out of scope.
- Disabled extension commands are hidden from normal command discovery. Direct
  invocation of a known disabled extension command returns
  `extension_disabled` with `meta.scope=local`.
- When gateway extension state is disabled, extension API calls return
  `extension_disabled` with `meta.scope=gateway` only after the caller identity
  and grant checks pass.
- The `solo` extension is registered so nodes and gateways can persist
  enablement state, but Solo command/API proxying is deferred in this release.

## Commands

The extension family has three commands.

1. [`orbit extension:list`](1_extension-list/extension-list.md)
2. [`orbit extension:enable <extension>`](2_extension-enable/extension-enable.md)
3. [`orbit extension:disable <extension>`](3_extension-disable/extension-disable.md)

## Related

- [`orbit cf-*`](../12_cf/README.md)
- [`orbit codex:app`](../23_codex/1_codex-app/codex-app.md)
