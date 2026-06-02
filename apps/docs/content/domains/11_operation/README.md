# Operation Commands

Operation commands cut across Orbit's command surface. They update Orbit installations, verify convergence, and run diagnostic workflows. Activity history reads live in their own family; see [`docs/domains/17_activity`](../17_activity/README.md).

The operation domain does not own a state family. Operation commands may read, write, verify, or repair state that belongs to other families, but permanent drift keys remain the product family keys defined by the architecture, such as `node`, `app`, `workspace`, `process`, `proxy`, `schedule`, `tool`, `firewall_rule`, and `database_connection`.

## State Ownership

The operation command domain does not own a state family. Operation commands are cross-family workflows.

`doctor` routes concrete probes and issue codes to the family that owns the state: `doctor --family=node`, `doctor --family=app`, `doctor --family=workspace`, `doctor --family=process`, `doctor --family=proxy`, `doctor --family=schedule`, `doctor --family=tool`, `doctor --family=firewall_rule`, and `doctor --family=database_connection`. Update commands may reference those families, but they must not invent operation-family drift.

## Domain Rules

These rules constrain all commands in the operation domain.

**Scope and authority:**

- Operation commands must not invent durable operation-domain configuration.
- Fleet-changing operation commands run through gateway-owned authority and node access policy.
- Local operation commands affect only the caller machine. A command that extends beyond the caller machine must document a gateway-mediated fleet path.

**Behavior contracts:**

- Updates change Orbit installations through the node-local Orbit CLI entry
  point. Production/artifact installs update and relink the native CLI binary
  artifact. Source-mounted Docker/Incus development and E2E topologies keep
  `/usr/local/bin/orbit` pointed at `<source>/apps/cli/orbit` and update by
  changing the mounted source. Gateway service replacement is a durable
  `update:all` operation that uses a digest-pinned `orbit-gateway` image and
  one-shot runner. Host PHP and host Composer are not supported gateway update
  fallbacks; production host PHP/Composer is required only on app-role nodes
  where app-source workflows need it.
- Updates do not replace doctor.
- For drift or runtime readiness questions after an update, run the doctor family that owns the changed artifact.
- `doctor` owns cross-family verification and resolution. Verify mode is read-only. `--fix` enables interactive resolution; `--restore` and `--adopt` force a single direction non-interactively.
- Family doctor contracts own concrete probes, issue codes, and restore/adopt action maps.
- Profiling reads request/runtime data and must not mutate app configuration.
- Operation commands emit activity entries through the cross-cutting Loggable contract. See [`activity-concepts.md`](../17_activity/activity-concepts.md).

## Commands

These are the commands in the operation domain.

1. [`orbit update`](1_update/update.md)
2. [`orbit update:all`](2_update-all/update-all.md)
3. [`orbit doctor`](3_doctor/doctor.md)
4. [`orbit profile [target]`](5_profile/profile.md)
