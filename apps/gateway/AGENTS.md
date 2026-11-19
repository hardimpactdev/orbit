# Orbit Gateway

Laravel 13 control plane for Orbit. This file holds every Gateway rule; read no other instruction file before a change. The root `AGENTS.md` owns workflow, checks and review.

## Boundaries

- The Gateway admits requests, records intent and projects the terminal result. Swamp executes ([command development pipeline](../../docs/reference/command-development-pipeline.mdx)). Write no Gateway execution code.
- Add no `orbit-agent` command: `apps/agent` is frozen and only observes. `AgentCommandRunner` callers go as their kind moves to Swamp ([Node agent](../../docs/reference/node-agent.md#commands)).
- Add no queue beyond those of the [Gateway queue](../../docs/reference/gateway-queue.md). Run a long API operation as an [operation](../../docs/reference/operations.md), not inside its request.
- Node access is binary and directed, enforced at the HTTP boundary. One access edge permits all commands for its serving node. The active Gateway peer is implicit authority, and access to the Gateway is fleet-wide. Add no granular permissions, presets, wildcards or compatibility code.
- An extension under `extensions/<name>/` has one provider that implements `App\Domain\Extensions\Extension`. Code under `App\` never uses `Orbit\Extensions\*`; core reaches an extension only through a plug-in point. Some extensions, such as Tasks, still live in core ([Extensions](../../docs/reference/extensions.md)).
- [Tools](../../docs/reference/tools.md) owns Tool Manager policy.

## Code

- Start every PHP file with `declare(strict_types=1)`.
- Keep controllers thin. Put behaviour in `final readonly` actions. Take input through Form Requests. Answer with the sibling controllers' JSON envelope (`data` from typed Spatie Data, `meta`); add no Eloquent API Resource.
- An API route sits behind `RequireActiveWireGuardPeer` by default. Copy the sibling route's middleware: the trust bootstrap routes `gateway:status` and `gateway:trust` are public, `copy:*` routes use `RequireTaskKey`, and Conn managed-target routes authenticate on their own.
- `bin/mcp-tools` generates the MCP tools from the API and skips callbacks, private and browser endpoints. `app/Http/Mcp` runs a tool as an internal API request: add no identity, access, validation or redaction logic there, and hand-write no tool for an API operation.
- Redact secrets before they reach a response, error, log or activity record. Pass a secret through stdin or a mode-0600 file, never through argv.
- The SQLite store defaults to `$ORBIT_HOME/gateway.sqlite`; `DB_DATABASE` overrides it. Migrations preserve existing control-plane state.

## Tests

- From `apps/gateway`, run the tests that cover the change: `vendor/bin/pest --compact tests/Feature/...Test.php`, or `--filter=<name>`. `composer test` runs the whole suite.
- Test authentication, validation, redaction and API errors at the boundary that handles them. The Gateway has no model factories: use the helpers and fakes in `tests/Support`, and contact no real host.
