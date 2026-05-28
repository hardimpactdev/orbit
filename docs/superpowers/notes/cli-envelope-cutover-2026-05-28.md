# CLI Envelope Cutover (D4 / Phase 2)

Source artifact for the canonical envelope migration in the CLI-first plan.

## Canonical shape

Success:

```json
{"success":{"data":{"example":true},"meta":{"request_id":"abc"}}}
```

Failure:

```json
{"error":{"code":"validation_failed","message":"Invalid input.","meta":{"field":"name"}}}
```

The canonical contract is also published in product docs (Phase 1: append a "Canonical JSON Envelope" section to `apps/docs/content/architecture.md` Command-and-API-model area, or create `apps/docs/content/domains/api-envelope.md`).

## Cutover release

**Tag:** TBD by maintainer in the Phase 2 PR.
**Date:** TBD.

Release notes call out the breaking change for: Solo orchestration scratchpads, Codex/Solo loop roles parsing `orbit ... --json`, repository `bin/` scripts, gateway controller test fakes, any external scripts.

## Direct consumer inventory

| Consumer | Path / scope | Action |
| --- | --- | --- |
| Solo orchestration scratchpads | `docs/superpowers/plans/solo-orchestration/` (loop role prompts that parse `orbit ... --json`) | Update prompt examples before cutover. |
| Codex/Solo loop roles | per-role prompts that parse JSON | Update during the Phase 2 PR. |
| Repository scripts | `bin/` (no current consumers found by `rg "orbit .*--json" bin/`) | Re-verify before cutover. |
| Gateway controller test fakes | `apps/cli/tests/`, `apps/gateway/tests/` test fixtures using `JsonEnvelope::success/failure` | Update with the helper rewrite (same PR). |
| External scripts | none known | Confirm in release-note review. |

## JsonEnvelope helper consumer audit

Found via `rg -n "JsonEnvelope::success|JsonEnvelope::failure" apps packages`:

**Test fixtures (Pest stubs)** — update with helper rewrite:

- `packages/core/tests/JsonEnvelopeTest.php`
- `apps/gateway/tests/Unit/Services/AgentIde/CoreAgentIdeWorkspacePathResolverTest.php`
- `apps/gateway/tests/Unit/Services/Workspaces/PolyscopeWorkspaceBranchAlignerTest.php`
- `apps/gateway/tests/Unit/Services/Workspaces/PolyscopeWorkspaceDriverTest.php`
- `apps/gateway/tests/Unit/Services/Vpn/WgEasyVpnBackendTest.php`
- `apps/gateway/tests/Feature/Services/Vpn/WgEasyServiceInstallerTest.php`
- `apps/gateway/tests/Feature/Commands/Vpn/VpnWebUiChangePasswordCommandTest.php`
- `apps/cli/tests/Feature/InternalWgEasyStateCommandTest.php`

**Production code** — small:

- `apps/cli/app/Commands/OrbitCommand.php` (the only production caller of `JsonEnvelope::success/failure` on the CLI side)

**Internal CLI commands** that emit JsonEnvelope-shaped JSON via their own code paths:

- `apps/cli/app/Commands/Internal/VerifyExecutorCommand.php`
- `apps/cli/app/Commands/Internal/WgEasyStateCommand.php`
- `apps/cli/app/Commands/Internal/WorkspaceAdapterLookupCommand.php`
- `apps/cli/app/Commands/Internal/WorkspaceAdapterUpdateCommand.php`

## Gateway controller response shape audit

Inventory via `rg -l "'success' =>\\s*\\[" apps/gateway/app/Http/Controllers/Api | wc -l`:

- **75 controllers already emit the target `'success' => [...]` shape inline** (no JsonEnvelope helper, no migration needed).
- **0 controllers emit the old `'ok' =>` shape** (confirmed via `rg -l "'ok'\\s*=>" apps/gateway/app/Http/Controllers/Api`).
- **Remaining 16 controllers** (of 91 total under `apps/gateway/app/Http/Controllers/Api`) do not call `response()->json([...])` at all (they return resources, redirects, or stream responses) — no migration needed for the envelope shape.

Per-controller status — abbreviated; the Phase 2 PR confirms each by re-running the rg before edit:

| Controller pattern | Current shape | Target shape | Audit status |
| --- | --- | --- | --- |
| All 75 `'success' => [` controllers | success | success (unchanged) | done — already canonical |
| 16 non-json-returning controllers | n/a | n/a | done — out of scope |
| `JsonEnvelope::success/failure` callers in tests | ok-data-meta | success/error | pending — port with helper rewrite |
| `apps/cli/app/Commands/OrbitCommand.php` | ok-data-meta | success/error | pending — port with helper rewrite |

**Net effect:** The "Phase 2 cannot land its JsonEnvelope rewrite until every row in the controller table has `audit status = done`" gate is effectively satisfied today for the controller surface. The remaining work is concentrated in the helper + tests + internal CLI commands + the single CLI production caller — a small, contained change.

## CLI consumer wrapping (D12)

`apps/cli/app/Commands/OrbitCommand.php` provides `renderSuccess($data, $meta = [])` and `renderFailure(...)`. Per D12 these helpers detect an inbound gateway-rendered envelope and unwrap `success.data` and `success.meta` rather than nesting. No `success.success` is ever emitted. Local-only and bootstrap commands construct the envelope themselves through the helpers. Tests assert no double-wrap on every ported command family.

## Cutover sequencing

1. This artifact is committed (ORBIT-CLI-02A) and the product-docs envelope contract section is added (Phase 1 docs work).
2. ORBIT-CLI-02B rewrites `packages/core/src/Http/JsonEnvelope.php`, updates the 4 test fixtures, updates `OrbitCommand.php`, and updates the 4 internal CLI commands to match the new shape.
3. Phase 6/8 family ports rely on `OrbitCommand::renderSuccess()` unwrap behavior (D12) so no per-family envelope handling is needed.
