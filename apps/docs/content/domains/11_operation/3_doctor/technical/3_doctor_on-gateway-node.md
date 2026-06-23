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

## Category Set by Target Roles

The rendered category set is derived from the *target* node's active roles, not
the calling peer's role. Every node with at least one active role assignment
includes `Processes`. A client/operator identity with no active role assignment
renders only `Node`.

| Target role assignment state | Categories |
| --- | --- |
| active `gateway` role (default or `--self`) | `Node`, `Processes`, `Scheduling` |
| client with no active role | `Node` |
| active `database` role only | `Node`, `Tools`, `Processes` |
| active `agent` role | `Node`, `Tools`, `Processes` |
| active `router` role | `Node`, `Proxy routes`, `Processes` |
| active `app-dev` role | `Node`, `Apps`, `Workspaces`, `Processes`, `Proxy routes`, `Firewall`, `Tools`, `Scheduling`, `Databases` |
| active `app-prod` role | `Node`, `Apps`, `Processes`, `Proxy routes`, `Firewall`, `Tools`, `Scheduling`, `Databases` |
| active `ingress` role | `Node`, `Proxy routes`, `Firewall`, `Tools`, `Processes` |
| active `websocket` role | `Node`, `Tools`, `Processes` |
| active `s3` role | `Node`, `Tools`, `Proxy routes`, `Processes` |
| active `metrics` role | `Node`, `Tools`, `Processes`, `Proxy routes` |
| active `vpn` or `analytics` role without another role-specific category | `Node`, `Processes` |

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
| `app` | Check app configuration and app-role runtime facts on the target node, including paths, document roots, runtime configuration, and app health probes declared by the app family. |
| `workspace` | Check workspace configuration and the target node's workspace reality, using app-suffixed workspace identifiers in human output. |
| `process` | Check process configuration and process runtime reality on the target node. |
| `proxy` | Check proxy route configuration and `orbit-caddy` backend reality on the target node. |
| `firewall_rule` | Check firewall rule configuration and backend firewall reality on the target node. |
| `tool` | Check tool configuration, installed versions, configuration, and lifecycle state on the target node. |
| `schedule` | Check schedule configuration, scheduler liveness, and recent schedule reality for the target node and its apps. |

All node-family facts render under the `Node` row today; a planned `DNS`/`DNS/TLD` slice will separate resolver-specific findings when available.

Family contracts remain authoritative for exact probe facts, issue codes, restore maps, and adopt maps. The gateway peer contract owns only where probing is orchestrated and which peer role is allowed to perform it.

## Resolution Boundary

Gateway peers may resolve drift only when `--fix`, `--restore`, or `--adopt` is supplied. Verify-mode runs must not mutate configuration or reality. Resolution orchestration uses `doctor` (interactive), `doctor --restore` (bulk gateway-to-node), or `doctor --adopt` (bulk node-to-gateway).

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
| `apps/gateway/tests/Feature/Commands/Operations/DoctorCommandContractTest.php` | Gateway-local execution for the node family, drift-detected exit semantics, restore/adopt action rendering, mutually exclusive flag rejection, unsupported family rejection, and `--node=<other>` cross-targeting from a gateway peer. |
| `apps/gateway/tests/Unit/Services/Doctor/DoctorReportRunnerTest.php` | Role-aware category set for gateway and other role-bearing targets, universal process-family support for role-bearing nodes, and family scope validation. |
| `apps/gateway/tests/Unit/Services/Doctor/DoctorReportRunnerTest.php` | Per-node probe scoping, restore action suppression, action failure recording, and family dispatch through the gateway-local runner path. |
