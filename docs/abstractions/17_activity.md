# Activity Implementation Patterns

Read this with `docs/abstractions/cross-cutting.md` before implementing
activity command ports or activity gateway API endpoints.

Product behavior remains owned by `docs/commands/17_activity/**`,
especially `activity-concepts.md`.

## Domain Constraints

- Activity is a non-state command domain. It does not create or own a separate
  doctor state family.
- The gateway database is the source of truth for durable activity history.
- Activity reads are historical gateway database reads. They must not inspect
  live node state, app runtimes, process logs, Caddy, firewall state, or the
  filesystem.
- Activity reads must not fix drift, adopt reality, enqueue repair work, or
  mutate product-family intent.
- The stored Spatie activity `event` column maps to the current activity
  doctrine field named `type`.
- The stored Spatie activity `properties.type` value maps to the current
  activity doctrine field named `effect` until a future storage migration
  deliberately renames that property.
- The stored Spatie activity `batch_uuid` maps to the current activity
  doctrine field named `correlation_id`.
- `activity:list` returns individual rows. Correlation metadata groups related
  rows but must not collapse them into synthetic entries.

## Read Surface Pattern

- Gateway API reads should run under the normal `WireGuardIdentity` and
  `LogActivity` middleware stack.
- The gateway activity list endpoint returns the standard `success` / `error`
  JSON envelope used by other gateway APIs.
- Read filters are applied against recorded activity relationships and
  declared activity properties, not live probes.
- `effect` filtering reads `properties.type` and must support `read`, `write`,
  and `destructive`.
- `correlation` filtering reads `batch_uuid`.
- Newest-first ordering is by activity id descending unless command docs later
  require a more specific timestamp tie-breaker.
- `limit` queries should fetch one extra row so the response can report
  `has_more` without running a second count query.
- The activity read controller itself is Loggable with `type=activity.listed`
  and `effect=read`; it records normalized filter values and returned row
  count after a successful read.

## Current Authorization Baseline

- `WireGuardIdentity` authenticates the gateway API caller by active node
  identity before activity rows are returned.
- Full activity visibility policy is gateway-owned and still needs dedicated
  implementation when command/API slices require scoped history. Do not add a
  broad legacy policy abstraction ahead of a concrete scoped-history need.

## Evidence Pointers

- `docs/commands/17_activity/README.md`
- `docs/commands/17_activity/activity-concepts.md`
- `docs/commands/17_activity/1_activity-list`
- `docs/commands/17_activity/2_activity-show`
- `docs/abstractions/cross-cutting.md`
- Old evidence: `../orbit-old-may/app/Console/Commands/ActivityLogListCommand.php`
- Old evidence: `../orbit-old-may/app/Console/Commands/ActivityLogShowCommand.php`
