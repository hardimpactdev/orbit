# Integration Operations

The Gateway records intent and validates commands. This family executes Reverb
AI router and integration work for Node role changes. Conn, Vault, Analytics,
Metrics and WebSocket registry kinds use their separate native workflows.

`registration.json` contains no Metrics or Analytics kinds. Their former polled
registrations are removed. Each ported parent uses one native DAG.

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
reporting failure.

Secrets enter retained remote effects only after Gateway hydration. Conn and
Vault use the shared direct private-material and secret-output routes. See
[Conn direct](../../../../docs/conn-pairing-direct.md) and
[Vault direct](../../../../docs/vault-direct.md). The Conn maintenance method
only admits pending registration cleanup intent; it performs no revocation.

Focused checks:

```sh
deno test --allow-read --allow-write --allow-env extensions/behaviors/operations/integrations/pipeline_test.ts
python3 extensions/behaviors/operations/vault/collection_test.py
deno fmt --check extensions/behaviors/operations/integrations
deno lint extensions/behaviors/operations/integrations
deno check extensions/behaviors/operations/integrations/mod.ts
```

The Vault collection program and its tests now live in the Vault direct family.
The Gateway remote-program catalog no longer includes `vault.collection`.

Metrics uses one native `operation-metrics-fleet` DAG. Its placed jobs own
exporter, service, Caddy and runtime work, with native step outputs for
recovery. The former Metrics polled registrations, leaf dispatcher, child
Operations and receipt tests are deleted. See
[Metrics fleet](../../../../docs/metrics-fleet-native.md).

All four WebSocket registry kinds use the direct `operation-websocket-registry`
workflow. Its control-plane worker reads only the admitted registry UUID, epoch
and revision, waits for current Reverb boot reports, and projects bounded
results. The previous Python confirmation program and the driver’s registry
run/receipt, pending-probe and supersession paths are removed. A transport
failure proves nothing; native recovery can resume confirmation. Cancellation
stops observation and preserves committed intent. Credentials never enter this
workflow.
