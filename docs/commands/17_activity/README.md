# Activity Commands

Activity commands surface durable gateway activity history and define the
cross-cutting logging contract that every state-changing command and API
endpoint participates in.

The activity command domain does not own a state family. Activity commands read
gateway-recorded events produced by other product families; the logging
doctrine governs *how* every other command emits activity, not what those
commands own.

## State Ownership

The activity command domain does not own a state family. Activity entries are
historical evidence of operations that read or mutated product-family state.
For live drift, run the family doctor that owns the entity recorded as the
activity subject:

- `doctor --family=node`
- `doctor --family=app`
- `doctor --family=workspace`
- `doctor --family=process`
- `doctor --family=proxy`
- `doctor --family=schedule`
- `doctor --family=tool`
- `doctor --family=firewall_rule`

`activity:list` and `activity:show` read gateway-owned history. They must not
invent activity-family drift, fix drift, adopt reality, or replace family
doctor contracts.

## Domain Rules

- Every state-changing command and API endpoint MUST emit an activity entry
  through the gateway-owned activity logger. See
  [`activity-concepts.md`](activity-concepts.md) for the contract.
- Activity logging records intent and outcome, not raw request or command
  arguments. Properties are declared per command; secrets are never logged.
- A single operation may produce multiple activity entries that share one
  correlation id (CLI command, gateway API call, gateway-orchestrated node
  enactment). Correlation is metadata; it does not collapse those entries.
- Activity history is gateway-owned. The gateway authorizes every read against
  the caller's node identity and the requested filters.
- Activity is historical evidence, not a substitute for live `doctor` probes.

## Commands

1. [`orbit activity:list`](1_activity-list/activity-list.md)
2. [`orbit activity:show [id]`](2_activity-show/activity-show.md)
