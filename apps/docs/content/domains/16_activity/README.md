# Activity Commands

Activity commands surface durable gateway activity history and define the
cross-cutting logging contract for gateway API endpoints. CLI commands do not
write activity directly.

The activity command domain does not own a state family. Activity commands read
gateway-recorded events produced by other product families. The logging
doctrine governs gateway emission for command-backed work. State changes that
happen only in the local CLI do not emit activity.

## State Ownership

The activity command domain does not own a state family. Activity entries are
durable records of operations that read or mutated product-family state.
For live drift, run the family doctor that owns the entity recorded as the
activity subject. The nine family doctors are:

| Family | Doctor command |
| --- | --- |
| `node` | `doctor --family=node` |
| `instance` | `doctor --family=instance` |
| `workspace` | `doctor --family=workspace` |
| `process` | `doctor --family=process` |
| `proxy` | `doctor --family=proxy` |
| `schedule` | `doctor --family=schedule` |
| `tool` | `doctor --family=tool` |
| `firewall_rule` | `doctor --family=firewall_rule` |
| `database_connection` | `doctor --family=database_connection` |

`activity:list` and `activity:show` read gateway-owned history. They must not
invent activity-family drift, fix drift, adopt reality, or replace family
doctor contracts.

## Domain Rules

These rules apply to gateway activity and to commands that call the gateway.

- Every gateway API operation that changes state MUST be recorded as an
  activity entry at the gateway chokepoint. A CLI command that calls a matching
  gateway endpoint relies on that endpoint's entry. State changes that happen
  only in the local CLI do not emit activity because the CLI has no trusted
  shared activity writer.
  Gateway-recorded flows such as `update:all` record at the gateway start route
  and durable runner instead. See
  [`activity-concepts.md`](activity-concepts.md) for the contract.
- Activity logging records configuration and outcome, not raw request or command
  arguments. Properties are declared per command; secrets are never logged.
- A single operation may produce multiple activity entries that share one
  correlation id (CLI command, gateway API call, gateway-orchestrated node
  apply). Correlation is metadata; it does not collapse those entries.
- Activity history is gateway-owned. The gateway authorizes every read against
  the caller's node identity and the requested filters.
- Activity is a durable record, not a substitute for live `doctor` probes.

## Commands

The activity family has two commands.

1. [`orbit activity:list`](1_activity-list/activity-list.md)
2. [`orbit activity:show [id]`](2_activity-show/activity-show.md)
