# Integration Operations

The Gateway records intent and validates every command and child. This family
executes Reverb registry changes, Vault collection changes, AI router,
Analytics, Metrics, Conn pairing effects, and the integration children of Node
role changes.

`registration.json` lists the kinds. The shared runner skips a kind while its
required Gateway capability is unavailable. Vault and Metrics list contracts
need C; pairing creation needs H. No family implements a substitute for these
shared contracts.

`mod.ts` receives a kind and worker identity, claims one Operation on that
placed worker, and saves its assignment before effects. A retry stays bound to
that Operation. A lost completion reply is confirmed through a normal Operation
read and cannot claim the next queued item. `pipeline.ts` runs that plan in
order. Each child request sends only `{worker,key}`. A pending child calls
`/wait` with its exact key and ends the workflow. A later claim re-enters the
same Operation and verifies the child's normal receipt. Failed children stop
dependent effects; retirement cleanup records partial failure after trying the
remaining children.

Remote attempts use Kit I. After a lost reply, the family reads the target
receipt. A terminal receipt proves the original attempt, a running receipt ends
this workflow attempt, and only an unknown receipt permits another run with the
same key. A proven `not_started` refusal leaves the step unfinished and retries
the same key after the precondition clears; it writes no failure receipt.
Gateway-local commands retain their own admitted idempotency rules. A lost
completion reply reuses the stored completion without another effect.

Reverb app writes rely on the running processes' existing registry
reconciliation. They never restart a serving process. Node role children consume
the retained parent's `role_intent`, including the explicit `created` flag and
original Settings scopes. Only a newly created WebSocket runtime rolls back
after a proven publication failure. Existing runtimes and their credentials
remain available.

AI router Route removal follows a proven healthy collector. A refused takeover
before publication restores the recorded Route and republishes it before
reporting failure. Metrics captures its fleet once, snapshots before mutation,
and restores from those exact same-parent snapshots when a later stage fails.
The serving exporter snapshot records `metrics-cancellation-boundary`. After
cancellation, only declared restore children may run. Service Caddy restoration
uses `metrics.service_caddy_restore {restore_key}` after the matching service
restore settles. The cancelled parent performs no further effects.

Secrets enter remote effects only after Gateway hydration. Conn's one-time
output is declared as a secret output owned by its pairing record. Swamp accepts
only its reference, never the credential. The Conn HTTP 201 path admits this
same workflow and waits for a bounded Gateway read; it contains no separate
pairing executor. A caller timeout leaves the existing Operation available for
recovery.

Focused checks:

```sh
deno test --allow-read --allow-write --allow-env extensions/behaviors/operations/integrations/mod_test.ts extensions/behaviors/operations/integrations/pipeline_test.ts extensions/behaviors/operations/integrations/metrics_test.ts
python3 /path/to/orbit/apps/gateway/resources/remote/vault/tests/test_collection.py
python3 extensions/behaviors/operations/integrations/native_test.py
deno fmt --check extensions/behaviors/operations/integrations
deno lint extensions/behaviors/operations/integrations
deno check extensions/behaviors/operations/integrations/mod.ts
```

The native tests use a disposable repository and a file-backed Gateway fixture.
They resume a lost target reply and a lost completion reply in a new Swamp
process. Live proof must wait for paired deployment and use an
orchestrator-selected harmless dev target. Preserve Hauzer and Mealou's running
realtime processes throughout rollout.

The Vault collection program tests live beside the shipped program in Orbit.
They cover collection reconciliation and the Orbit-CA-only, host and redirect
boundaries. Deploy this workflow on a placed worker before merging the Vault and
Conn cutovers.

Route parents request `metrics.reconcile {}` on the active Metrics Node. The
Gateway captures the fleet and admits that exact child once. Its placed workflow
uses `metrics.receipt` after proven exporter, service and runtime children.
Reconciliation keeps Metrics publication in place: it does no certificate or
private DNS work. Retained Gateway callers keep their synchronous fleet
implementation.
