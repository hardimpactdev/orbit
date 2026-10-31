# Native Node footprint convergence

Standalone convergence uses this DAG on selected persistent Linux workers. Nodes
1 and 18 retain their existing polled path. Initial worker enrollment and
existing enrollment/role parent Operations retain their current contracts.
Admission derives scope from stored Node state; HTTP callers cannot select it.
The shared per-kind map selects `node.converge=direct` for this scope.
Unsupported applicable contributions, including the collector lifecycle, refuse
before any Node effect, even when a stored artifact digest matches.

`operation-node-converge-direct` composes one native run per `node.converge`
intent. The Gateway captures immutable desired records and templates, starts the
run and projects bounded artifact outcomes. It performs no SSH or live Node
inspection during admission. It creates no child Operations for this path.

The native DAG reads the intent after the Gateway binds the start response. Each
stage observes cancellation in a native checkpoint before its effect; the
desired snapshot stays immutable. Resume reuses that checkpoint so an uncertain
write reconciles before a later cancellation can become terminal. Once an effect
starts, it finishes and verifies its outcome. It then installs the admitted
agent binary, handles Caddy package/publication, peer DNS, the VPN listener and
DNS publication, offline Route residue, every admitted PHP runtime, and
tmpfiles. Its final Node stage configures the observer and persistent worker
through lane 21's canonical `agent.configure` action. Only applicable artifacts
dispatch a local action. Matching artifacts that need live repair checks use the
convergent native primitive and report unchanged when its live state already
matches.

Every step inherits the exact `orbit-node-<node_id>` target and matching `node`
label from the root workflow. Native assertions use Swamp’s built-in evaluator
and load no extension code; the Node-code lint checks behavior tasks against
reviewed local sources. Native behavior locking, dependency conditions, writes
declarations and outputs govern execution. There is no fallback worker, family
relay, Gateway lease, wait or receipt. Resource definitions use the platform's
one-day output bound; workflow concurrency, timeouts and recovery retain native
defaults.

The root checks both Node identity and agent digest before invoking the
canonical `convergeNodeNetwork` callable. Its strict input/result schemas come
from `node/network_direct.ts`; the callable reuses Fleet's `peer_dns_local.ts`
flow. Null network input dispatches no network action. The local action performs
no Gateway read, projection or child start.

## Private material

The root's safe snapshot contains no credentials or hash values. The agent stage
reads `secret_hash` and `worker_credentials` through the existing bound private
callback and passes them through stdin to the fixed sudo command. Worker
material uses lane 21's existing staging and exact owner checks.

Direct configure passes `allow_secret_rotation=false`. Under the native agent
update lock, a missing or mismatched recorded hash refuses before secret/config
changes. Existing callers keep their default rotation behavior. The direct
callback discards the returned hash and all unneeded fields. It never calls
`storeSecretOutput`, persists private material in native output, or changes the
rekey command or its recovery guards.

The shared native harness also exercises real loopback `gatewayHttp` success and
secret-bearing failure responses, then scans the complete scratch, history,
stdout and stderr for synthetic sentinels. This proves the local callback
boundary; TLS, live authorization and sudo still require the worker canary.

## Results and recovery

Each stage writes one typed native outcome. A known refusal stops later effects
through a native assertion, and the final job projects the bounded failure. An
exception without a typed outcome remains uncertain: the final job does not
invent a terminal result. Native resume reuses completed stages; after a lost
projection reply it retries only projection. Convergent primitives reconcile an
uncertain local action before reporting its result.

Caddy render and validation refusals are skipped. A first-visit validation
refusal remains a final failure unless the artifact was already a repair. Other
Caddy failures stop convergence. Private DNS publication requires a proven
listener installation first. PHP runtime convergence covers every admitted
version. Known residue failures retain the captured rows and increment attempts
once; successful projection removes only rows that still match the admitted
snapshot. Uncertain residue effects do not fabricate a skip.

## Remaining release gates

- Preserve actual parent/bootstrap and workerless consumers. This scoped release
  removes the synchronous HTTP executor and artifact apply adapters. Remaining
  polled registrations serve only those retained contexts; direct intents are
  excluded by their immutable writer stamp.
- Sensitive review covers private worker material, nonrotation and residue
  projection. Preserve the parent/rekey recovery tests and shared helpers.
- Confirm the workflow's loaded identity and exact existing-token run/read grant
  before releasing the writer.
- Prove exact-worker placement, start-binding order and credential redaction on
  a disposable/dev target. No live worker canary is claimed by this draft.
- On approved merge-first release, canary within minutes and revert on failure.

Source checks: `converge_direct_test.ts`, `network_direct_test.ts`, Fleet's
local peer-DNS tests and `converge_direct_native_test.py`. The native fixture
uses an isolated repository and injected effects; it does not establish worker
readiness.
