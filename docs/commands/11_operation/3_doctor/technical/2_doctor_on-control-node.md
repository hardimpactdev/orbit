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
| `verify` | Resolve the single-node scope, forward the request to the gateway, stream gateway-owned progress, and render the returned diagnostic. |

## Single-Node Scope

`doctor` always targets one node per run. On a control node, the default
target is the local control node identity (equivalent to `--self`). An
operator may target a different node with `--node=<other>`; multi-node
scopes are not supported.

- Resolve caller role before scope, forwarding, or rendering.
- Resolve `--self` to the control node's gateway-known identity before
  forwarding.
- Resolve `--node=<other>` against gateway intent and use that node's role
  to derive the rendered category set.
- Reject `--self` combined with `--node`.
- Do not infer gateway-local or app-local defaults on the control node. App
  and workspace filters are forwarded only when explicit options are present
  or when the gateway can resolve them from authorized scope.

## Category Set By Target Role

The rendered category set is derived from the *target* node's role, not the
caller role. The control node forwards the request, the gateway authorizes
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

The control node sends one doctor orchestration request to the gateway. The
gateway owns:

- scope authorization;
- target-role resolution and category-set derivation;
- family dispatch;
- gateway-intent reads;
- node reality inspection on the target node;
- final diagnostic shape.

The control node owns:

- command-line validation that can be performed before forwarding
  (mutually exclusive flags, family-key validity);
- gateway transport and gateway-unavailable handling;
- human or JSON rendering of the gateway result;
- activity logging for the local CLI invocation.

Control callers must not:

- SSH to app or gateway nodes for doctor probes;
- read or mutate gateway database state directly;
- run local family probes for non-control fleet reality;
- repair or adopt state from `doctor` without `--fix`; resolution requires
  `doctor --fix --restore` or `doctor --fix --adopt`.

## Progress And Rendering

In human mode, progress is gateway-streamed when transport supports it. The
control node renders the same doctor check-up frame defined by
[`6.1_doctor_output-render_human.md`](6.1_doctor_output-render_human.md),
restricted to the target-role category set.

If streaming progress is unavailable, the control node may show a gateway
request state until the final diagnostic arrives, then render the final
framed result. It must not fabricate per-category progress that the gateway
did not report.

In JSON mode, no human progress frame is emitted. The final gateway
diagnostic is returned in the shared envelope defined by
[`6.2_doctor_output-render_json.md`](6.2_doctor_output-render_json.md).

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
| `tests/Feature/Commands/Operations/DoctorCommandContractTest.php` | Control-caller forwarding through the typed gateway request, mutually exclusive flag rejection, unsupported family rejection, app-node write-mode denial, verify request shape, and rendered panel structure. |
