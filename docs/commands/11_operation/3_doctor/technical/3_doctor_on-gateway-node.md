# Technical Contract: `doctor` On A Gateway Node

[Back to `doctor` technical contract.](1_doctor.md)

This page describes caller-role behavior when `orbit doctor` is invoked from a
gateway node.

**Effects:** `read`, `stream`.

**Prerequisites:**
- The local caller role resolves to `gateway`.
- The local gateway node has an active gateway registry row.
- The selected scope can be resolved from gateway intent.
- Remote app/control/gateway nodes selected by family probes are reachable when
  those families need node reality.

## Allowed Paths

Gateway callers are the authority path for doctor.

| Mode | Behavior |
| --- | --- |
| `verify` | Resolve scope, read gateway intent, probe selected node reality, and return the diagnostic. |

## Scope Resolution

- Resolve caller role before probes or side effects.
- Resolve `--self` to the local gateway node identity.
- Resolve `--node`, `--app`, and `--workspace` against gateway intent.
- Apply authorization before probing selected remote nodes.
- Omitted `--family` expands to every doctor-supported family.
- Narrow family scopes render and probe only the selected families.

## Probe Orchestration

The gateway owns the family dispatch loop. Each selected family may combine
gateway-intent reads with local or remote reality checks:

| Family | Gateway-owned probing behavior |
| --- | --- |
| `node` | Check gateway node records, local gateway identity, WireGuard peer intent, node reachability, and node bootstrap reality. |
| `app` | Check app intent and app-node runtime facts, including paths, document roots, runtime configuration, and app health probes declared by the app family. |
| `workspace` | Check workspace intent and app-node workspace reality, using app-suffixed workspace identifiers in human output. |
| `process` | Check process intent and process supervisor/runtime reality on the owning app node. |
| `proxy` | Check proxy route intent and Caddy or proxy backend reality on the owning node. |
| `firewall_rule` | Check firewall rule intent and backend firewall reality on eligible nodes. |
| `tool` | Check tool intent, installed versions, configuration, and lifecycle state on selected nodes. |
| `schedule` | Check schedule intent, scheduler liveness, and recent schedule reality for selected nodes/apps. |

Family contracts remain authoritative for exact probe facts, issue codes,
fix maps, and adopt maps. The gateway role contract owns only where probing is
orchestrated and which role is allowed to perform it.

## Resolution Boundary

Gateway callers may resolve drift only when `--fix` is supplied. Verify-mode runs
must not mutate intent or reality. Restore/adopt orchestration belongs to
`doctor --fix --restore` or `doctor --fix --adopt`.

Gateway callers must not:

- treat backend implementation names as public families;
- create, restore, adopt, or remove durable records from `doctor` verify-mode runs.

## Progress And Rendering

In human mode, the gateway renders the doctor check-up frame directly. Category
rows update as each family starts, gathers results, finishes, skips, or fails.
Issue and action details render inline under the category that owns them.

In JSON mode, no human progress frame is emitted. The final diagnostic is
returned in the shared envelope defined by
[`6.2_doctor_output-render_json.md`](6.2_doctor_output-render_json.md).

## Failure Semantics

- Scope and validation failures fail before probes.
- Authorization failures fail before probes or side effects.
- Probe failures are represented in the diagnostic payload when enough scope
  context exists to render the category result.
- Drift found by verify returns the diagnostic payload and the standard command
  failure status.

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Operations/DoctorOnGatewayNodeContractTest.php` | Gateway authority path, local scope resolution, selected family dispatch, remote probing handoff, read-only guarantee, and no backend-name public families. |
| `tests/E2E/Read/DoctorTest.php` | Real read-only gateway doctor verification for selected families. |
