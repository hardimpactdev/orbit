# Technical Contract: `doctor` On A Gateway Node

[Back to `doctor` technical contract.](1_doctor.md)

This page describes behavior when `orbit doctor` is invoked from a gateway node — that is, when the gateway identifies the calling WireGuard peer as the gateway itself.

**Effects:** `read`, `stream`.

**Prerequisites:**
- The gateway identifies the calling peer as the gateway.
- The local gateway has an active gateway registry row.
- The selected target node can be resolved from gateway configuration.
- Remote nodes selected via `--node=<other>` are reachable through the gateway-owned probe path.

## Allowed Paths

Gateway peers are the authority path for doctor.

| Mode | Behavior |
| --- | --- |
| `verify` | Resolve the single-node scope, or explicit `--all` fleet scope, read gateway configuration, probe the selected node reality, and return the diagnostic. |
| `interactive`, `restore`, `adopt` | Resolve the single-node scope, read gateway configuration, probe the target node's reality, apply the resolution actions declared safe by the owning family, and return the diagnostic. |

## Single-Node Scope

Plain `doctor` resolves one target node. The CLI first forwards the locally
configured default node when one is selected. If no default node is configured,
the gateway resolves the local gateway node identity. An operator may target a
different node with `--node=<other>`. Fleet verification is explicit `--all`
only.

- The gateway resolves `--self` to its own node identity.
- The gateway resolves `--node`, `--app`, and `--workspace` against its configuration.
- The gateway rejects `node=all`; fleet verification uses `all=true`.
- Reject `--self` combined with `--node`.
- Apply authorization before probing the selected target node.

## Category Set by Target Eligibility

Gateway implicit authority changes authorization only; it does not change
family eligibility. After selecting the target, the gateway resolves the
role-derived base categories and owned-fact/platform overlays defined by the
[canonical category model](1_doctor.md#target-eligibility-and-category-set).
That model includes VPN DNS under `Tools`, Orbit-protected rules on eligible
Ubuntu nodes under `Firewall`, and `Scheduling` for the gateway plus every node
targeted by a schedule. A narrow `--family` filter intersects with the resolved
eligibility set; ineligible families are rejected before probes.

DNS/TLD facts currently live inside the `Node` row. A separate `DNS/TLD`
slice for operator/app targets and a `DNS` slice for gateway targets is
planned but not yet emitted; the row will be added when a DNS diagnostic
source lands.

## Probe Orchestration

The gateway owns the family dispatch loop for each resolved target. A
single-node run scopes every selected family to that node; explicit `--all`
verify mode runs the same per-node loop through the bounded fleet pool. Each
selected family may combine gateway-configuration reads with local or remote
reality checks scoped to its current target:

| Family | Gateway-owned probing behavior |
| --- | --- |
| `node` | Check the target node's gateway record, identity, WireGuard peer configuration, reachability, bootstrap reality, and current DNS/TLD facts. |
| `app` | Check app configuration and app-role runtime facts on the target node, including paths, document roots, runtime configuration, and app health probes declared by the app family. |
| `workspace` | Check workspace configuration and the target node's workspace reality, using app-suffixed workspace identifiers in human output. |
| `database_connection` | Check gateway connection records and target mappings, then inspect selected app-instance and workspace environment facts through the database family's documented gateway-local or Agent-push path. |
| `process` | Check process configuration and process runtime reality on the target node. |
| `proxy` | Check proxy route configuration and `orbit-caddy` backend reality on the target node. |
| `firewall_rule` | Check firewall rule configuration and backend firewall reality on the target node. |
| `tool` | Check tool configuration, installed versions, configuration, and lifecycle state on the target node. |
| `schedule` | Check schedule configuration, scheduler liveness, and recent schedule reality for the target node and its apps. |

All node-family facts render under the `Node` row today; a planned `DNS`/`DNS/TLD` slice will separate resolver-specific findings when available.

Family contracts remain authoritative for exact probe facts, issue codes, restore maps, and adopt maps. The gateway peer contract owns only where probing is orchestrated and which peer role is allowed to perform it.

## Fleet Verification Concurrency

Explicit `--all` fleet verification probes eligible nodes in batches of up to five
concurrent node checks. Additional nodes stay queued until a running node
finishes. Streamed fleet progress still emits per-node `running` and `done`
events with the same partial and final doctor envelope shape.

The gateway runs each node probe in a bounded pool of `artisan`
`orbit:internal:doctor-fleet-probe-node` subprocess workers so HTTP-served
doctor requests do not depend on `pcntl_fork`. Final fleet `nodes[]` and
`issues[]` ordering follow the resolved target-node order even when probes
complete out of order.

When `proc_open` is unavailable or the gateway database is an isolated
`:memory:` sqlite database, fleet verification falls back to the same
deterministic serial node order.

## Resolution Boundary

Gateway peers may resolve drift only when `--fix`, `--restore`, or `--adopt` is supplied. Verify-mode runs must not mutate configuration or reality. Resolution orchestration uses `doctor --fix` (interactive), `doctor --restore` (bulk gateway-to-node), or `doctor --adopt` (bulk node-to-gateway).

Gateway peers must not:

- treat backend implementation names as public families;
- create, restore, adopt, or remove durable records from `doctor` verify-mode runs;
- probe more than one target node in a single-node or resolution-mode run.
  Explicit `--all` verify mode may use the bounded fleet pool defined above.

## Progress and rendering

In human mode, the gateway renders the doctor check-up frame directly, restricted to the target's resolved eligibility set. Active roles establish the base categories; owned facts and platform support add overlays. Category rows update as each family starts, gathers results, finishes, skips, or fails. Issue and action details render inline under the category that owns them.

In JSON mode, no human progress frame is emitted. The final diagnostic is returned in the shared envelope defined by [`6.2_doctor_output-render_json.md`](6.2_doctor_output-render_json.md).

## Failure Semantics

- Scope and validation failures fail before probes.
- Authorization failures fail before probes or side effects.
- Probe failures are represented in the diagnostic payload when enough scope
  context exists to render the category result.
- Drift found by verify returns the diagnostic payload and the standard
  command failure status.

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Operation/DoctorCommandTest.php` | Gateway-node doctor invocation, scope forwarding, and rendered output from gateway context. |
| `apps/gateway/tests/Feature/Http/Api/DoctorRunControllerTest.php` | Gateway verify authorization, scope validation, and diagnostic payload responses. |
