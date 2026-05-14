# Technical Contract: `doctor` On A Control Node

[Back to `doctor` technical contract.](1_doctor.md)

This page describes behavior when `orbit doctor` is invoked from a control node — that is, when the gateway identifies the calling WireGuard peer as a control peer.

**Effects:** `read`, `stream`.

**Prerequisites:**
- The CLI can reach the Orbit gateway.
- The gateway identifies the calling peer as a control peer.
- The gateway authorizes that peer to inspect the selected scope.

## Allowed Paths

Control peers do not probe fleet reality directly. The CLI is a client of the gateway doctor endpoint.

| Mode | Behavior |
| --- | --- |
| `verify` | Forward the single-node scope to the gateway, stream gateway-owned progress, and render the returned diagnostic. |
| `interactive`, `restore`, `adopt` | Forward the resolution request to the gateway, stream gateway-owned progress, and render the returned diagnostic. Allowed when the gateway authorizes the resolved scope. |

## Single-Node Scope

`doctor` always targets one node per run. When the calling peer is identified as a control peer, the default target is that peer's identified node (equivalent to `--self`). An operator may target a different node with `--node=<other>`; multi-node scopes are not supported.

- Forward `--self` to the gateway; the gateway resolves it to the calling peer's identified node.
- Forward `--node=<other>` to the gateway; the gateway resolves it and uses that node's role to derive the rendered category set.
- Reject `--self` combined with `--node` before forwarding.
- The CLI does not infer gateway-local or app-local defaults.
- App and workspace filters are forwarded only when explicit options are present.

## Category set by target role

The rendered category set is derived from the *target* node's role, not the
calling peer's role. The CLI forwards the request, the gateway authorizes
and probes, and the renderer shows only categories that apply to the target.

| Target role | Categories |
| --- | --- |
| `control` (default or `--self`) | `Node` |
| `gateway` (via `--node=<gateway>`) | `Node` |
| `app` (via `--node=<app-node>`) | `Node`, `Apps`, `Workspaces`, `Processes`, `Proxy routes`, `Firewall`, `Tools`, `Scheduling` |

A narrow `--family` filter intersects with the target role's set; families
outside the set are rejected before probes.

DNS/TLD facts currently live inside the `Node` row. A separate `DNS/TLD`
slice for control/app targets and a `DNS` slice for gateway targets is
planned but not yet emitted; the row will be added when a DNS diagnostic
source lands.

## Probe Orchestration

The CLI sends one doctor orchestration request to the gateway. The gateway owns:

- scope authorization;
- target-role resolution and category-set derivation;
- family dispatch;
- gateway-configuration reads;
- node reality inspection on the target node;
- final diagnostic shape.

The CLI owns:

- command-line validation that can be performed before forwarding (mutually exclusive flags, family-key validity);
- gateway transport and gateway-unavailable handling;
- human or JSON rendering of the gateway result;
- activity logging for the local CLI invocation.

Control peers must not:

- SSH to app or gateway nodes for doctor probes;
- read or mutate gateway database state directly;
- run local family probes for non-control fleet reality;
- repair or adopt state from `doctor` without a resolution mode flag; resolution requires `doctor --fix`, `doctor --restore`, or `doctor --adopt`.

## Progress and rendering

In human mode, progress is gateway-streamed when transport supports it. The CLI renders the same doctor check-up frame defined by [`6.1_doctor_output-render_human.md`](6.1_doctor_output-render_human.md), restricted to the target-role category set.

If streaming progress is unavailable, the CLI may show a gateway request state until the final diagnostic arrives, then render the final framed result. It must not fabricate per-category progress that the gateway did not report.

In JSON mode, no human progress frame is emitted. The final gateway diagnostic is returned in the shared envelope defined by [`6.2_doctor_output-render_json.md`](6.2_doctor_output-render_json.md).

## Failure Semantics

- Gateway connection failures fail before probes.
- Gateway authorization failures fail before probes or side effects.
- Gateway validation failures use the shared failure envelope and preserve
  gateway failure metadata.
- Drift found by verify returns the diagnostic payload and the standard
  command failure status.

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Operations/DoctorRoleAwareCategoriesTest.php` | Single-node scope default to `--self`, role-aware category set per target role, `--family` rejection for families outside the target role's set, and `--node=<other>` target-role scoping for app-family probes. |
| `tests/Feature/Commands/Operations/DoctorCommandContractTest.php` | Control-peer forwarding through the typed gateway request, mutually exclusive flag rejection, unsupported family rejection, app-node write-mode denial, verify request shape, and rendered panel structure. |
