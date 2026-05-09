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
| `verify` | Allowed for authorized scopes. The app node forwards orchestration to the gateway and may contribute caller-local defaults only when a family contract defines them. |

## Scope Resolution

- Resolve caller role before probes, forwarding, prompts, or side effects.
- Resolve `--self` to the app node's gateway-known identity.
- When no `--node` is supplied, app-node verify scopes are limited by gateway
  authorization; they are not automatically fleet-wide.
- Local app/workspace context may help choose defaults only when the selected
  family contract explicitly defines that behavior.
- App-node local filesystem reality is not enough to authorize an app or
  workspace scope. The gateway must still authorize the resulting scope.

## Probe Orchestration

App callers are not the authority path for doctor. In normal verify mode, the
app node forwards the request to the gateway. The gateway owns authorization,
family dispatch, and final diagnostic construction.

Family contracts may define narrow verify-mode local context behavior, such as:

- resolving the current app from the caller's working directory;
- resolving a workspace from the caller's working directory;
- including app-node self facts when the selected scope is `--self`.

Those local facts are inputs to gateway-authorized orchestration. They do not
grant permission to inspect unrelated apps, unrelated nodes, or gateway-private
state.

App callers must not:

- run generic fleet-wide probes directly from the app node;
- SSH to other nodes for doctor probes;
- mutate gateway intent or node reality from `doctor`;
- repair node, proxy, firewall, tool, schedule, or unrelated app/workspace state
  merely because the CLI is available on the app node.

## Progress And Rendering

In human verify mode, the app node renders gateway-streamed progress when
available and then renders the framed result panel defined by
[`6.1_doctor_output-render_human.md`](6.1_doctor_output-render_human.md).

If streaming progress is unavailable, the app node may show a gateway request
state until the final diagnostic arrives. It must not fabricate category
progress outside gateway-reported state.

In JSON mode, no human progress frame is emitted.

## Failure Semantics

- Gateway connection failures fail before probes.
- Authorization failures fail before probes or side effects.
- Drift remaining after verify returns the diagnostic payload and the standard
  command failure status.

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Operations/DoctorOnAppNodeContractTest.php` | App-caller verify forwarding, `--self` app-node identity forwarding, local context default boundaries, no direct remote probing, and no unauthorized write side effects. |
| `tests/E2E/Read/DoctorAppNodeVerifyTest.php` | Real app-node verify smoke coverage for authorized self/app/workspace scopes. |
