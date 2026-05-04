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

## First Review Focus

The first node `family-review` todo must evaluate caller-role resolution and
branching as a possible shared service. The review should not extract that
service unless the boundary is concrete and focused regression coverage is in
scope.
