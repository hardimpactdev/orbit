# Technical Contract: `doctor` On An App Node

[Back to `doctor` technical contract.](1_doctor.md)

This page describes behavior when `orbit doctor` is invoked from an app node — that is, when the gateway identifies the calling WireGuard peer as an app peer.

**Effects:** `read`, `stream`.

**Prerequisites:**
- The CLI can reach the Orbit gateway.
- The gateway identifies the calling peer as an app peer.
- The gateway authorizes that peer to inspect the selected scope.
- Any local app/workspace defaults used by a family are defined by that family contract.

## Allowed Paths

| Mode | Behavior |
| --- | --- |
| `verify` | Allowed for gateway-authorized single-node scopes. The CLI forwards orchestration to the gateway and may contribute working-directory defaults only when a family contract defines them. |
| `interactive`, `restore`, `adopt` | Denied by the gateway before side effects unless the selected family doctor contract documents a narrow app-node write-mode exception. |

## Single-Node Scope

`doctor` always targets one node per run. When the calling peer is identified as an app peer, the default target is that peer's identified node (equivalent to `--self`). An operator may target a different node with `--node=<other>`; multi-node scopes are not supported.

- Forward `--self` to the gateway; the gateway resolves it to the calling peer's identified node.
- Reject `--self` combined with `--node` before forwarding.
- App-node working-directory reality is not enough to authorize an app or workspace scope. The gateway must still authorize the resulting scope.
- Working-directory hints may help choose defaults, but only for families whose contract explicitly defines this behavior.

## Category set by target role

The rendered category set is derived from the *target* node's role, not the
calling peer's role.

| Target role | Categories |
| --- | --- |
| `app` (default or `--self`) | `Node`, `Apps`, `Workspaces`, `Processes`, `Proxy routes`, `Firewall`, `Tools`, `Scheduling` |
| `gateway` (via `--node=<gateway>`) | `Node` |
| `control` (via `--node=<control>`) | `Node` |

A narrow `--family` filter intersects with the target role's set; families
outside the set are rejected before probes.

DNS/TLD facts currently live inside the `Node` row. A separate `DNS/TLD`
slice for control/app targets and a `DNS` slice for gateway targets is
planned but not yet emitted; the row will be added when a DNS diagnostic
source lands.

## Probe Orchestration

App peers are not the authority path for doctor. In normal verify mode, the CLI forwards the request to the gateway. The gateway owns authorization, family dispatch, and final diagnostic construction for the single-node target.

Family contracts may define narrow local context behavior for verify mode, such as:

- resolving the current app from the caller's working directory;
- resolving a workspace from the caller's working directory;
- including app-node self facts when the selected target is the calling peer's node.

Those working-directory hints are inputs to gateway-authorized orchestration. They do not grant permission to inspect unrelated apps, unrelated nodes, or gateway-private state.

App peers must not:

- run generic fleet-wide probes directly from the app node;
- probe more than one target node per run;
- SSH to other nodes for doctor probes;
- mutate gateway configuration or node reality from `doctor`;
- repair node, proxy, firewall, tool, schedule, or unrelated app/workspace state merely because the CLI is available on the app node.

## Progress and rendering

In human verify mode, the CLI renders gateway-streamed progress when available and then renders the framed result panel defined by [`6.1_doctor_output-render_human.md`](6.1_doctor_output-render_human.md), restricted to the target-role category set.

If streaming progress is unavailable, the CLI may show a gateway request state until the final diagnostic arrives. It must not fabricate category progress outside gateway-reported state.

In JSON mode, no human progress frame is emitted.

## Failure Semantics

- Gateway connection failures fail before probes.
- Authorization failures fail before probes or side effects.
- Drift remaining after verify returns the diagnostic payload and the
  standard command failure status.

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Operations/DoctorCommandContractTest.php` | App-peer `--fix`/`--restore`/`--adopt` denial before side effects, gateway-forwarded verify path, and shared scope/output assertions used across peer roles. |
| `tests/Feature/Commands/Operations/DoctorRoleAwareCategoriesTest.php` | Role-aware category set for app target, full backend-family probe set when scope is the app node itself, and per-node scoping for app/workspace/proxy probes. |
