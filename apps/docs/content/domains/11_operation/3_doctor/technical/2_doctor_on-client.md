# Technical Contract: `doctor` On An Operator Node

[Back to `doctor` technical contract.](1_doctor.md)

This page describes behavior when `orbit doctor` is invoked from an operator
node; that is, when the gateway identifies the calling WireGuard peer as an
operator peer.

**Effects:** `read`, `stream`.

**Prerequisites:**
- The CLI can reach the Orbit gateway.
- The gateway identifies the calling peer as an operator peer.
- The gateway authorizes that peer to inspect the selected scope.

## Allowed Paths

Operator peers do not probe node reality directly. The CLI is a client of the
gateway doctor endpoint.

| Mode | Behavior |
| --- | --- |
| `verify` | Forward the resolved single-node scope, or explicit `--all` fleet scope, to the gateway, stream gateway-owned progress, and render the returned diagnostic. |
| `interactive`, `restore`, `adopt` | Forward the resolution request to the gateway, stream gateway-owned progress, and render the returned diagnostic. Allowed when the gateway authorizes the resolved scope. |

## Single-Node Scope

Plain `doctor` resolves one target node. The CLI first forwards the locally
configured default node when one is selected. If no default node is configured,
the CLI sends `self=true` and the gateway resolves the calling peer's identified
node. An operator may target a different node with `--node=<other>`. Fleet
verification is explicit `--all` only.

- Forward `--self` to the gateway; the gateway resolves it to the calling peer's identified node.
- Forward `--node=<other>` to the gateway; the gateway resolves the selected target.
- Reject `--node=all`; fleet verification uses `--all`.
- Reject `--self` combined with `--node` before forwarding.
- The CLI may forward its configured local default node for omitted scope.
- The CLI forwards `self=true` for omitted scope when no default node exists.
- Instance and workspace filters are forwarded only when explicit options are present.

## Category Set by Target Eligibility

The CLI forwards the request; it never derives Doctor eligibility from the
calling peer's role. The gateway authorizes the selected target, then resolves
the role-derived base categories and owned-fact/platform overlays defined by
the [canonical category model](1_doctor.md#target-eligibility-and-category-set).
That model includes DNS base/runtime capability under `Tools`, Orbit-protected rules on eligible
Ubuntu nodes under `Firewall`, and `Scheduling` for the gateway plus every node
targeted by a schedule. A narrow `--family` filter intersects with the resolved
eligibility set; ineligible families are rejected before probes.

DNS projection and runtime findings remain in their owning `Node`, `Proxy
routes`, and `Tools` rows. No separate DNS row is emitted.

## Probe Orchestration

The CLI sends one doctor orchestration request to the gateway. The gateway owns:

- scope authorization;
- target eligibility resolution and category-set derivation;
- family dispatch;
- gateway-configuration reads;
- node reality inspection on the target node;
- final diagnostic shape.

The CLI owns:

- command-line validation that can be performed before forwarding (mutually exclusive flags, family-key validity);
- gateway transport and gateway-unavailable handling;
- human or JSON rendering of the gateway result.

The local CLI does not write activity. Forwarded `POST /doctor/run` owns the
gateway activity entry.

Operator peers must not:

- SSH to app or gateway nodes for doctor probes;
- read or mutate gateway database state directly;
- run local family probes for non-operator fleet reality;
- repair or adopt state from `doctor` without a resolution mode flag; resolution requires `doctor --fix`, `doctor --restore`, or `doctor --adopt`.

## Progress and rendering

In human mode, progress is gateway-streamed when transport supports it. The CLI renders the same doctor check-up frame defined by [`6.1_doctor_output-render_human.md`](6.1_doctor_output-render_human.md), restricted to the target's resolved eligibility set.

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
| `apps/cli/tests/Feature/Commands/Operation/DoctorCommandTest.php` | CLI scope selection, panel rendering, JSON and stream output, and gateway request forwarding from client context. |
| `apps/gateway/tests/Feature/Http/Api/DoctorRunControllerTest.php` | Gateway verify scope, authorization failure handling, and diagnostic response shape. |
| `apps/gateway/tests/Unit/Services/Doctor/DoctorReportRunnerTest.php` | Role-aware category sets, universal process-family support for role-bearing nodes, and family scope validation. |
