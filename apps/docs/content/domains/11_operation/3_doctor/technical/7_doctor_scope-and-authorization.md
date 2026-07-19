# Technical Contract: `doctor` Scope and Authorization Rules

[Back to `doctor` technical contract.](1_doctor.md)

This page owns the cross-peer scope-resolution rules and grant requirements
that the global [`doctor` technical contract](1_doctor.md) inherits.
Authorization details for client and gateway execution live in the on-node
companion contracts:
[`2_doctor_on-client.md`](2_doctor_on-client.md),
and [`3_doctor_on-gateway-node.md`](3_doctor_on-gateway-node.md).

## Scope and Authorization Rules

- Resolve and validate all scope filters before probes or side effects.
- Resolve a single-node target before probes unless explicit `--all` fleet
  verify mode is selected.
- Apply gateway-owned grant authorization to the resolved scope before probes or side effects.
- Verify mode requires `doctor:verify` on the resolved target node.
- Resolution actions require the matching doctor permission on the resolved
  target node: `doctor:restore` or `doctor:adopt`.
- Reject mutually exclusive option combinations before probes.
- Mutually exclusive pairs: `--fix`/`--restore`, `--fix`/`--adopt`, `--restore`/`--adopt`, and `--self`/`--node`.
- `--all` is mutually exclusive with `--node`, `--self`, `--app`,
  `--workspace`, `--fix`, `--restore`, and `--adopt`.
- Reject `--node=all` with `validation_failed` and `field=node` metadata before
  probes.
- Reject unresolvable family, node, app, or workspace scopes before probes.
- Reject family selections outside the target node's resolved eligibility set
  before probes. Active roles provide the base set; gateway-owned facts and
  platform support add overlays. `process` is eligible for every role-bearing
  node, `tool` includes DNS base/runtime capability, `firewall_rule` includes eligible
  Ubuntu protected-rule targets, and `schedule` includes the gateway plus every
  node targeted by a schedule definition.
- Reject mode requests unsupported by the selected family before side effects.

## Grant Boundaries

- CLI availability is not generic doctor write permission.
- Gateway nodes have implicit authority through the gateway exception.
- Non-gateway callers need the matching permission on the resolved target node.
- Working-directory hints may assist scope resolution in verify mode.
- Such hints apply only where the family contract defines that behavior.
- Working-directory hints do not authorize mutation of gateway configuration or node reality.

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Operation/DoctorCommandTest.php` | Mutually exclusive flag rejection, unsupported family rejection, and authorization failure handling at the CLI boundary. |
| `apps/gateway/tests/Feature/Http/Api/DoctorRunControllerTest.php` | Gateway verify scope enforcement and authorization failures before probes run. |
