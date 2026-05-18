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
| `verify` | Resolve the single-node scope, read gateway configuration, probe the target node's reality, and return the diagnostic. |
| `interactive`, `restore`, `adopt` | Resolve the single-node scope, read gateway configuration, probe the target node's reality, apply the resolution actions declared safe by the owning family, and return the diagnostic. |

## Single-Node Scope

`doctor` always targets one node per run. When the calling peer is the gateway itself, the default target is the local gateway node identity (equivalent to `--self`). An operator may target a different node with `--node=<other>`; multi-node scopes are not supported.

- The gateway resolves `--self` to its own node identity.
- The gateway resolves `--node`, `--app`, and `--workspace` against its configuration.
- Reject `--self` combined with `--node`.
- Apply authorization before probing the selected target node.

## Category Set by Target Roles

The rendered category set is derived from the *target* node's active roles, not
the calling peer's role.

| Target role assignment state | Categories |
| --- | --- |
| active `gateway` role (default or `--self`) | `Node` |
| joined client with no active hosted role | `Node` |
| active `database` role only | `Node`, `Tools` |
| active `app-development` or `app-production` role | `Node`, `Apps`, `Workspaces`, `Processes`, `Proxy routes`, `Firewall`, `Tools`, `Scheduling` |

A narrow `--family` filter intersects with the target active-role set; families
outside the set are rejected before probes.

DNS/TLD facts currently live inside the `Node` row. A separate `DNS/TLD`
slice for operator/app targets and a `DNS` slice for gateway targets is
planned but not yet emitted; the row will be added when a DNS diagnostic
source lands.

## Probe Orchestration

The gateway owns the family dispatch loop for the single-node target. Each selected family may combine gateway-configuration reads with local or remote reality checks scoped to that one node:

| Family | Gateway-owned probing behavior |
| --- | --- |
| `node` | Check the target node's gateway record, identity, WireGuard peer configuration, reachability, bootstrap reality, and current DNS/TLD facts. |
| `app` | Check app configuration and app-node runtime facts on the target app node, including paths, document roots, runtime configuration, and app health probes declared by the app family. |
| `workspace` | Check workspace configuration and the target app node's workspace reality, using app-suffixed workspace identifiers in human output. |
| `process` | Check process configuration and process supervisor/runtime reality on the target app node. |
| `proxy` | Check proxy route configuration and Caddy or proxy backend reality on the target node. |
| `firewall_rule` | Check firewall rule configuration and backend firewall reality on the target node. |
| `tool` | Check tool configuration, installed versions, configuration, and lifecycle state on the target node. |
| `schedule` | Check schedule configuration, scheduler liveness, and recent schedule reality for the target node and its apps. |

All node-family facts render under the `Node` row today; a planned `DNS`/`DNS/TLD` slice will separate resolver-specific findings when available.

Family contracts remain authoritative for exact probe facts, issue codes, restore maps, and adopt maps. The gateway peer contract owns only where probing is orchestrated and which peer role is allowed to perform it.

## Resolution Boundary

Gateway peers may resolve drift only when `--fix`, `--restore`, or `--adopt` is supplied. Verify-mode runs must not mutate configuration or reality. Resolution orchestration uses `doctor --fix` (interactive), `doctor --restore` (bulk gateway-to-node), or `doctor --adopt` (bulk node-to-gateway).

Gateway peers must not:

- treat backend implementation names as public families;
- create, restore, adopt, or remove durable records from `doctor` verify-mode runs;
- probe more than one target node per run.

## Progress and rendering

In human mode, the gateway renders the doctor check-up frame directly, restricted to the target-role category set. Category rows update as each family starts, gathers results, finishes, skips, or fails. Issue and action details render inline under the category that owns them.

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
| `tests/Feature/Commands/Operations/DoctorCommandContractTest.php` | Gateway-local execution for the node family, drift-detected exit semantics, restore/adopt action rendering, mutually exclusive flag rejection, unsupported family rejection, and `--node=<other>` cross-targeting from a gateway peer. |
| `tests/Feature/Commands/Operations/DoctorRoleAwareCategoriesTest.php` | Role-aware category set for gateway target, single-node scope default to `--self`, and rejection of out-of-role families. |
| `tests/Unit/Services/Doctor/DoctorReportRunnerTest.php` | Per-node probe scoping, restore action suppression, action failure recording, and family dispatch through the gateway-local runner path. |
