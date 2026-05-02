# Operation Commands

Operation commands cut across Orbit's command surface. They update Orbit
installations, verify convergence, inspect operational history, and run
diagnostic workflows.

The operation domain does not own a state family. Operation commands may read,
write, verify, or repair state that belongs to other families, but permanent
drift keys remain the product family keys defined by the blueprint, such as
`node`, `app`, `workspace`, `process`, `proxy`, `schedule`, `tool`, and
`firewall_rule`.

## State Ownership

The operation command domain does not own a state family. Operation commands are
cross-family workflows.

`doctor` routes concrete probes and issue codes to the family that owns the
state: `doctor --family=node`, `doctor --family=app`,
`doctor --family=workspace`, `doctor --family=process`,
`doctor --family=proxy`, `doctor --family=schedule`,
`doctor --family=tool`, and `doctor --family=firewall_rule`. Update and
activity commands may reference those families, but they must not invent
operation-family drift.

## Domain Rules

- Operation commands must not invent durable operation-domain intent.
- Fleet-changing operation commands run through gateway-owned authority and node
  access policy.
- Local operation commands affect only the caller machine unless the command
  explicitly documents a gateway-mediated fleet path.
- Updates change Orbit installations. They do not replace doctor; run the
  doctor family that owns the changed artifact when configuration drift or
  runtime readiness matters.
- `doctor` owns cross-family convergence orchestration. Family doctor contracts
  own concrete probes, issue codes, and fix/adopt action maps.
- Operational history is gateway history. A single operation may produce
  multiple records under one correlation id when history support is available.
- Profiling reads request/runtime data and must not mutate app intent.

## Commands

1. [`orbit update`](1_update/update.md)
2. [`orbit update:all`](2_update-all/update-all.md)
3. [`orbit doctor`](3_doctor/doctor.md)
4. [`orbit activity:list`](4_activity-list/activity-list.md)
5. [`orbit activity:show [id]`](5_activity-show/activity-show.md)

## Not Yet Converted

The legacy operation commands below are tracked in
[`docs/PORTING.md`](../../PORTING.md) and are not part of this converted domain
until their current contracts are added here:

- `profile`
