# Technical Contract: `doctor` On A Gateway Node

[Back to `doctor` technical contract.](1_doctor.md)

This page describes caller-role behavior when `orbit doctor` is invoked from a
gateway node.

**Effects:** `read`, `stream`.

**Prerequisites:**
- The local caller role resolves to `gateway`.
- The local gateway node has an active gateway registry row.
- The selected target node can be resolved from gateway intent.
- Remote nodes selected via `--node=<other>` are reachable through the
  gateway-owned probe path.

## Allowed Paths

Gateway callers are the authority path for doctor.

| Mode | Behavior |
| --- | --- |
| `verify` | Resolve the single-node scope, read gateway intent, probe the target node's reality, and return the diagnostic. |

## Single-Node Scope

`doctor` always targets one node per run. On a gateway node, the default
target is the local gateway node identity (equivalent to `--self`). An
operator may target a different node with `--node=<other>`; multi-node
scopes are not supported.

- Resolve caller role before probes or side effects.
- Resolve `--self` to the local gateway node identity.
- Resolve `--node`, `--app`, and `--workspace` against gateway intent.
- Reject `--self` combined with `--node`.
- Apply authorization before probing the selected target node.

## Category Set By Target Role

The rendered category set is derived from the *target* node's role, not the
caller role.

| Target role | Categories |
| --- | --- |
| `gateway` (default or `--self`) | `Node`, `DNS` |
| `control` (via `--node=<control>`) | `Node`; `DNS/TLD` only when custom TLD resolvers are configured on the target |
| `app` (via `--node=<app-node>`) | `Node`, `DNS/TLD`, `Apps`, `Workspaces`, `Processes`, `Proxy routes`, `Firewall`, `Tools`, `Scheduling` |

A narrow `--family` filter intersects with the target role's set; families
outside the set are rejected before probes.

## Probe Orchestration

The gateway owns the family dispatch loop for the single-node target. Each
selected family may combine gateway-intent reads with local or remote
reality checks scoped to that one node:

| Family | Gateway-owned probing behavior |
| --- | --- |
| `node` | Check the target node's gateway record, identity, WireGuard peer intent, reachability, and bootstrap reality. The `Node` row covers identity, runtime, and bootstrap facts. The `DNS` row (gateway target) covers gateway dev DNS resolver health and resolver safety. The `DNS/TLD` row (control or app target) covers custom resolvers or `nodes.tld` mapping for that target. |
| `app` | Check app intent and app-node runtime facts on the target app node, including paths, document roots, runtime configuration, and app health probes declared by the app family. |
| `workspace` | Check workspace intent and the target app node's workspace reality, using app-suffixed workspace identifiers in human output. |
| `process` | Check process intent and process supervisor/runtime reality on the target app node. |
| `proxy` | Check proxy route intent and Caddy or proxy backend reality on the target node. |
| `firewall_rule` | Check firewall rule intent and backend firewall reality on the target node. |
| `tool` | Check tool intent, installed versions, configuration, and lifecycle state on the target node. |
| `schedule` | Check schedule intent, scheduler liveness, and recent schedule reality for the target node and its apps. |

Family contracts remain authoritative for exact probe facts, issue codes,
fix maps, and adopt maps. The gateway role contract owns only where probing
is orchestrated and which role is allowed to perform it.

## Resolution Boundary

Gateway callers may resolve drift only when `--fix` is supplied. Verify-mode
runs must not mutate intent or reality. Restore/adopt orchestration belongs
to `doctor --fix --restore` or `doctor --fix --adopt`.

Gateway callers must not:

- treat backend implementation names as public families;
- create, restore, adopt, or remove durable records from `doctor` verify-mode runs;
- probe more than one target node per run.

## Progress And Rendering

In human mode, the gateway renders the doctor check-up frame directly,
restricted to the target-role category set. Category rows update as each
family starts, gathers results, finishes, skips, or fails. Issue and action
details render inline under the category that owns them.

In JSON mode, no human progress frame is emitted. The final diagnostic is
returned in the shared envelope defined by
[`6.2_doctor_output-render_json.md`](6.2_doctor_output-render_json.md).

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
| `tests/Feature/Commands/Operations/DoctorOnGatewayNodeContractTest.php` | Gateway authority path, single-node scope default to `--self`, `--node=<other>` target-role rendering, rejection of `--self` + `--node`, target-role family dispatch, read-only guarantee, and no backend-name public families. |
| `tests/E2E/Read/DoctorTest.php` | Real read-only gateway doctor verification for the gateway target. |
