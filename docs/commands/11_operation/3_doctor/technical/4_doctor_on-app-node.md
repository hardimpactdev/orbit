# Technical Contract: `doctor` On An App Node

[Back to `doctor` technical contract.](1_doctor.md)

This page describes caller-role behavior when `orbit doctor` is invoked from an
app node.

**Effects:** `read`, `stream`.

**Prerequisites:**
- The local caller role resolves to `app`.
- The app node can reach the Orbit gateway.
- The app node identity is authorized to inspect the selected scope.
- Any local app/workspace defaults used by a family are defined by that family
  contract.

## Allowed Paths

| Mode | Behavior |
| --- | --- |
| `verify` | Allowed for authorized single-node scopes. The app node forwards orchestration to the gateway and may contribute caller-local defaults only when a family contract defines them. |

## Single-Node Scope

`doctor` always targets one node per run. On an app node, the default target
is the local app node identity (equivalent to `--self`). An operator may
target a different node with `--node=<other>`; multi-node scopes are not
supported.

- Resolve caller role before probes, forwarding, prompts, or side effects.
- Resolve `--self` to the app node's gateway-known identity.
- Reject `--self` combined with `--node`.
- App-node local filesystem reality is not enough to authorize an app or
  workspace scope. The gateway must still authorize the resulting scope.
- Local app/workspace context may help choose defaults only when the
  selected family contract explicitly defines that behavior.

## Category Set By Target Role

The rendered category set is derived from the *target* node's role, not the
caller role.

| Target role | Categories |
| --- | --- |
| `app` (default or `--self`) | `Node`, `DNS/TLD`, `Apps`, `Workspaces`, `Processes`, `Proxy routes`, `Firewall`, `Tools`, `Scheduling` |
| `gateway` (via `--node=<gateway>`) | `Node`, `DNS` |
| `control` (via `--node=<control>`) | `Node`; `DNS/TLD` only when custom TLD resolvers are configured on the target |

A narrow `--family` filter intersects with the target role's set; families
outside the set are rejected before probes.

## Probe Orchestration

App callers are not the authority path for doctor. In normal verify mode,
the app node forwards the request to the gateway. The gateway owns
authorization, family dispatch, and final diagnostic construction for the
single-node target.

Family contracts may define narrow verify-mode local context behavior, such
as:

- resolving the current app from the caller's working directory;
- resolving a workspace from the caller's working directory;
- including app-node self facts when the selected target is the local
  app node.

Those local facts are inputs to gateway-authorized orchestration. They do
not grant permission to inspect unrelated apps, unrelated nodes, or
gateway-private state.

App callers must not:

- run generic fleet-wide probes directly from the app node;
- probe more than one target node per run;
- SSH to other nodes for doctor probes;
- mutate gateway intent or node reality from `doctor`;
- repair node, proxy, firewall, tool, schedule, or unrelated app/workspace
  state merely because the CLI is available on the app node.

## Progress And Rendering

In human verify mode, the app node renders gateway-streamed progress when
available and then renders the framed result panel defined by
[`6.1_doctor_output-render_human.md`](6.1_doctor_output-render_human.md),
restricted to the target-role category set.

If streaming progress is unavailable, the app node may show a gateway
request state until the final diagnostic arrives. It must not fabricate
category progress outside gateway-reported state.

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
| `tests/Feature/Commands/Operations/DoctorOnAppNodeContractTest.php` | App-caller verify forwarding, single-node scope default to `--self`, `--node=<other>` target-role rendering, rejection of `--self` + `--node`, local context default boundaries, no direct remote probing, and no unauthorized write side effects. |
| `tests/E2E/Read/DoctorAppNodeVerifyTest.php` | Real app-node verify smoke coverage for the local app-node target. |
