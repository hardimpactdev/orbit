# Orbit Gateway

Laravel 13 control plane for Orbit. This file holds every Gateway rule. Read no other instruction file before a change; the root `AGENTS.md` owns the workflow, checks and review.

## Boundaries

- The Gateway admits requests, records intent and projects the terminal result. Swamp executes ([command development pipeline](../../docs/reference/command-development-pipeline.mdx)). Write no Gateway execution code.
- Add no `orbit-agent` command: `apps/agent` is frozen and only observes. Existing `AgentCommandRunner` callers go away as their kind moves to Swamp ([Node agent](../../docs/reference/node-agent.md#commands)).
- Add no queue beyond those of the [Gateway queue](../../docs/reference/gateway-queue.md). Run a long API operation as an [operation](../../docs/reference/operations.md), not inside its request.
- Node access is binary and directed, enforced at the HTTP boundary. One access edge permits all commands for its serving node. The active Gateway peer is implicit authority, and access to the Gateway is fleet-wide. Add no granular permissions, presets, wildcards, or permission compatibility code.
- An optional feature lives in `extensions/<name>/` with one provider that implements `App\Domain\Extensions\Extension`. Code under `App\` never uses `Orbit\Extensions\*`; core reaches an extension only through a plug-in point ([Extensions](../../docs/reference/extensions.md)).
- [Tools](../../docs/reference/tools.md) owns Tool Manager policy, input, protection, adoption and removal.

## Code

- Start every PHP file with `declare(strict_types=1)`.
- Keep controllers thin. Put behaviour in `final readonly` actions. Take input through Form Requests and return typed Spatie Data objects, not Eloquent API Resources.
- Put a new API route behind `RequireActiveWireGuardPeer`, as its siblings in `routes/api/` are.
- The MCP server in `app/Http/Mcp` runs each API operation as an internal API request. Add no identity, access, validation or redaction logic there, and hand-write no tool for an API operation.
- Redact secrets before they reach a response, error, log or activity record. Pass a secret through stdin or a mode-0600 file, never through argv.
- The SQLite store lives under `$ORBIT_HOME`. Migrations preserve existing control-plane state.

## Tests

- From `apps/gateway`, run the tests that cover the change: `vendor/bin/pest --compact tests/Feature/...Test.php`, or `--filter=<name>`. `composer test` runs the whole suite.
- Test authentication, validation, redaction and API errors at the boundary that handles them. The Gateway has no model factories: use the helpers and fakes in `tests/Support`, and contact no real host.
