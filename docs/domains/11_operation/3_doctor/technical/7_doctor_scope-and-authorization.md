# Technical Contract: `doctor` Scope and Authorization Rules

[Back to `doctor` technical contract.](1_doctor.md)

This page owns the cross-peer scope-resolution rules and app-node write
boundaries that the global [`doctor` technical contract](1_doctor.md) inherits.
Authorization tables specific to each peer role live in the on-node companion
contracts:
[`2_doctor_on-control-node.md`](2_doctor_on-control-node.md),
[`3_doctor_on-gateway-node.md`](3_doctor_on-gateway-node.md), and
[`4_doctor_on-app-node.md`](4_doctor_on-app-node.md).

## Scope and Authorization Rules

- Resolve and validate all scope filters before probes or side effects.
- Resolve a single-node target before probes; multi-node scopes are not supported.
- Apply gateway-owned authorization to the resolved scope before probes or side effects.
- Reject mutually exclusive option combinations before probes.
- Mutually exclusive pairs: `--fix`/`--restore`, `--fix`/`--adopt`, `--restore`/`--adopt`, and `--self`/`--node`.
- Reject unresolvable family, node, app, or workspace scopes before probes.
- Reject family selections outside the target node's active-role category set before probes.
- Reject mode requests unsupported by the selected family before side effects.

## App-Node Write Boundaries

- App-node CLI availability is not generic doctor write permission.
- The gateway authorizes verify-mode scopes for app-node peers.
- The gateway denies `--fix`, `--restore`, or `--adopt` from app-node peers.
- A documented narrow app-node exception in the selected family doctor contract permits a resolution mode.
- Working-directory hints from app-node peers may assist scope resolution in verify mode.
- Such hints apply only where the family contract defines that behavior.
- Working-directory hints do not authorize mutation of gateway configuration or node reality.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Operations/DoctorCommandContractTest.php` | Scope resolution, mutually exclusive flag rejection, family-key validation, gateway authorization by peer role, and app-node write-mode denial. |
| `tests/Feature/Commands/Operations/DoctorRoleAwareCategoriesTest.php` | Rejection of family selections outside the target node's active-role category set. |
