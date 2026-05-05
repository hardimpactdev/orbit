# Node Implementation Patterns

Read this with `docs/abstractions/cross-cutting.md` before implementing node
command ports.

Product behavior remains owned by `docs/commands/1_node/**` and the top-level
product docs.

## Domain Constraints

- The gateway is the source of truth for node registry state.
- The local node identity is the `nodes.is_local=true` row.
- Only one local node row may be marked local at a time.
- Node access grants authorize Orbit operations; they do not grant SSH.
- Control callers must not SSH directly to app nodes after bootstrap.
- `node:new` owns first-gateway and app-node bootstrap exceptions.

## Evidence Pointers

- `docs/commands/1_node/README.md`
- `app/Models/Node.php`
- `app/Console/Commands/Internal/BootstrapGatewayLocalCommand.php`
- `app/Console/Commands/NodeNewCommand.php`
- `app/Console/Commands/NodeGrantCommand.php`
- `app/Console/Commands/NodeRevokeCommand.php`

## Shared Pattern Authority

Caller-role resolution, typed gateway requests, API envelope parsing, renderer
pairing, and role-path test shapes are documented in
`docs/abstractions/cross-cutting.md`. Read that file before implementing any
node command port.

The node family does not duplicate cross-cutting invariants here. Domain rules
below are node-specific.
