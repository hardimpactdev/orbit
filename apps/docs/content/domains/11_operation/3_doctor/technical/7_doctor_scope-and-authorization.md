# Technical Contract: `doctor` Scope and Authorization Rules

[Back to `doctor` technical contract.](1_doctor.md)

This page owns the cross-peer scope-resolution rules and grant requirements
that the global [`doctor` technical contract](1_doctor.md) inherits.
Authorization details for client and gateway execution live in the on-node
companion contracts:
[`2_doctor_on-operator-node.md`](2_doctor_on-operator-node.md),
and [`3_doctor_on-gateway-node.md`](3_doctor_on-gateway-node.md).

## Scope and Authorization Rules

- Resolve and validate all scope filters before probes or side effects.
- Resolve a single-node target before probes; multi-node scopes are not supported.
- Apply gateway-owned grant authorization to the resolved scope before probes or side effects.
- Verify mode requires `doctor:verify` on the resolved target node.
- Resolution actions require the matching doctor permission on the resolved
  target node: `doctor:restore` or `doctor:adopt`.
- Reject mutually exclusive option combinations before probes.
- Mutually exclusive pairs: `--fix`/`--restore`, `--fix`/`--adopt`, `--restore`/`--adopt`, and `--self`/`--node`.
- Reject unresolvable family, node, app, or workspace scopes before probes.
- Reject family selections outside the target node's active-role category set before probes.
- Reject mode requests unsupported by the selected family before side effects.

## Grant Boundaries

- CLI availability is not generic doctor write permission.
- Gateway nodes have implicit authority through the gateway exception.
- Non-gateway callers need the matching permission on the resolved target node.
- Working-directory hints may assist scope resolution in verify mode.
- Such hints apply only where the family contract defines that behavior.
- Working-directory hints do not authorize mutation of gateway configuration or node reality.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Commands/Operations/DoctorCommandContractTest.php` | Scope resolution, mutually exclusive flag rejection, family-key validation, and gateway authorization failures. |
| `apps/gateway/tests/Feature/Commands/Operations/DoctorRoleAwareCategoriesTest.php` | Rejection of family selections outside the target node's active-role category set. |
