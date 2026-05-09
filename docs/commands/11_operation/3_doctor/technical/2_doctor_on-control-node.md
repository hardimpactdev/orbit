# Technical Contract: `doctor` On A Control Node

[Back to `doctor` technical contract.](1_doctor.md)

This page describes caller-role behavior when `orbit doctor` is invoked from a
control node.

**Effects:** `read`, `stream`.

**Prerequisites:**
- The local caller role resolves to `control`.
- The control node can reach the Orbit gateway.
- The control node identity is authorized to inspect the selected scope.

## Allowed Paths

Control callers do not probe fleet reality directly. They are clients of the
gateway doctor endpoint.

| Mode | Behavior |
| --- | --- |
| `verify` | Forward the resolved scope to the gateway, stream gateway-owned progress, and render the returned diagnostic. |

## Scope Resolution

- Resolve caller role before probes or forwarding.
- Resolve command options locally enough to reject invalid combinations such as
  `--self --node`.
- Resolve `--self` to the control node's gateway-known identity before
  forwarding.
- Do not infer gateway-local or app-local defaults on the control node. App and
  workspace defaults are supplied only when explicit options are present or when
  the gateway can resolve them from authorized scope.

## Probe Orchestration

The control node sends one doctor orchestration request to the gateway. The
gateway owns:

- scope authorization;
- family dispatch;
- gateway-intent reads;
- remote node probing;
- final diagnostic shape.

The control node owns:

- command-line validation that can be performed before forwarding;
- gateway transport and gateway-unavailable handling;
- human or JSON rendering of the gateway result;
- activity logging for the local CLI invocation.

Control callers must not:

- SSH to app or gateway nodes for doctor probes;
- read or mutate gateway database state directly;
- run local family probes for non-control fleet reality;
- repair or adopt state from `doctor` without `--fix`; resolution requires `doctor --fix --restore` or `doctor --fix --adopt`.

## Progress And Rendering

In human mode, progress is gateway-streamed when transport supports it. The
control node renders the same doctor check-up frame defined by
[`6.1_doctor_output-render_human.md`](6.1_doctor_output-render_human.md).

If streaming progress is unavailable, the control node may show a gateway
request state until the final diagnostic arrives, then render the final framed
result. It must not fabricate per-category progress that the gateway did not
report.

In JSON mode, no human progress frame is emitted. The final gateway diagnostic
is returned in the shared envelope defined by
[`6.2_doctor_output-render_json.md`](6.2_doctor_output-render_json.md).

## Failure Semantics

- Gateway connection failures fail before probes.
- Gateway authorization failures fail before probes or side effects.
- Gateway validation failures use the shared failure envelope and preserve
  gateway failure metadata.
- Drift found by verify returns the diagnostic payload and the standard command
  failure status.

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Operations/DoctorOnControlNodeContractTest.php` | Control-caller forwarding, local pre-forward validation, `--self` identity forwarding, gateway-unavailable behavior, verify request shape, streamed progress handoff, and no direct local family probing. |
| `tests/E2E/Read/DoctorTest.php` | Real read-only control-node doctor verification against an active fleet. |
