# Native Metrics fleet reconciliation

`metrics.reconcile` records the complete desired fleet and starts one native DAG.
The Gateway owns intent, the existing private credential source, and a minimal
result projection. The polled dispatcher, PHP fleet orchestration, child
Operations, receipts and completion contracts are deleted.

Each Node uses its exact worker target and matching Node label. Native control
steps gate snapshots and all effects on availability and complete scope. Missing
workers fail explicitly before host changes. Nodes 1 and 18 use normal exact
placement, including when their workers are hosted by an SSH proxy. Workers are never substituted or silently skipped.

Native dependencies order snapshot, apply, service, current Caddy publication,
verify, runtime commit, restoration and root projection. Private material stays
inside the effect callback. Fresh public Caddy reads use the shared participant
GET and the canonical per-Node publication lock. Root result POST remains
root-only. No family relay or Gateway child is created.

Caddy follows current desired state. Recovery restores exporter/FPM/service
snapshots; it does not claim Caddy snapshot rollback. Unknown writes have no
outcome and are not automatically restored. Three native assertions retain
bounded apply/service/runtime outcomes because the pinned runtime does not
expose failed-method resource writes as step outputs.

The canonical native harness covers failure and recovery with fake host effects;
those cases do not count as a disposable dev canary.

The fleet workflow also handles `metrics.exporter_enable` and `metrics.exporter_disable`.
The separate `metrics.credentials_reset` native root verifies the pending password
before changing it. Each workflow uses native concurrency one.

Exporter, service, Docker runtime, certificate and Grafana firewall lower functions
execute system programs through the canonical placed Node transport. No Metrics
behavior invokes an agent action. The agent remains frozen at its published pin;
existing action code stays until the final observer strip.
