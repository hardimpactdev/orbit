---
title: Native Cluster Router transitions
description: An admitted Router set or unset runs on209 through official SSH and canonical Route children.
---

`cluster.router_set` and `cluster.router_unset` capture complete public intent before starting one native Swamp run. The input includes the candidate, obsolete assignments, canonical package prerequisites, SSH connections with stored host keys, certificate intents, and before/prepared/withdrawn/after Caddy, PHP and DNS snapshots. Admission reads stored state and the public certificate root. It does not query a Node account or change the selected Router.

Every step runs on209. Official `@swamp/ssh` reaches the admitted target. The Node role lower resolves the account and installs prerequisites and the pinned Caddy package. Router ingress and workload LAN allowances share the canonical Route LAN child and its official pinned SSH actions. Rules use Node-wide port, protocol and source tuples. Candidate-to-workload certificate probes run after the workload allowances and before DNS selection. Native Route children publish certificates and host state, retain both Routers through the canonical DNS grace, and then withdraw obsolete sites, scopes and allowances. Scope removal checks fresh loaded Caddy references.

A failed publication restores the captured previous configuration through the same Route children. A failure during obsolete Router baseline cleanup retains the selected candidate and the unfinished obsolete assignments. Native resume owns recovery. No step reads intent from Gateway or reports an intermediate checkpoint.

The shared terminal subscriber reads history once. The Cluster projector records the native `selected`, `restored`, `cleanup_complete`, `ready` and bounded failure fields. It changes only the admitted Router assignment IDs and leaves unrelated roles intact. Source merge, native publication and an authorized live Operation are separate evidence.

The root composes preparation, shared forward and restore publication children, and obsolete Router retirement. Each publication child uses the canonical Route mutation factory. Native `allowFailure` lets its final typed summary retain partial DNS selection and migration results; guards still suppress later effects after failure, and the factory proves every required contribution. The root restores on a failed forward summary and fails after recording its terminal result. Certificate signing always receives the root run ID.

The official cfgmgmt firewall capability expresses all Router ingress: HTTPS 443/tcp and DNS 53/tcp+udp from eligible member LAN addresses. Full site catalogs retain tuples shared by surviving Routes. After every official tuple is observed, the existing one-time migration child can remove exact old Router tags when `legacy_router: true` is admitted. Without that flag it keeps its existing Route-only behavior. It never removes Node baseline WireGuard rules. No custom Router firewall engine, transport or timer is added.

Certificate execution uses the before/after difference in Node, scope, domain and public trust identity. Unchanged workload leaves remain in the complete Caddy and cleanup catalogs but do not request or publish a certificate again. Affected leaves are grouped by Node. Each Node runs one certificate child, stages the shipped assets once, requests its public CSRs together and sends one batch to the existing Gateway signer. Official SSH ControlMaster reuses one connection across request, publication, trust and inspection actions.

Each action remains a separate native step. The child proves every admitted Node, scope and version before prepared Caddy can load; restoration uses the same grouping for only the reverse difference.

Root signing admission includes both differences. Every certificate child gives its shell signer a distinct behavior name using that child run ID, so concurrent fan-out items do not share a model lock. The signing request still carries the original parent run ID.

Certificate signing and decoding steps use a control-worker target alone. Retained role certificate fan-outs give each request, publication and record item a run-and-item behavior identity; the original run ID remains the signing authority. A failed leaf does not prove the parent is terminal while another contribution remains running. Recovery uses authoritative native history and the normal shared projection. Queue failures, model-lock failures and cancellation acknowledgement are distinct evidence; none authorizes a family poller, forced projection or replay.

## Proof budget and phase order

The existing Gateway budget for each Router set or unset is 2,700 seconds. It is provisional: no full successful Router run has supplied a p95. Op 6592 took 2,417.179 seconds including restoration; its forward phase took 1,345.582 seconds and restoration 944.722 seconds. Its 119 probe observations had a queue-inclusive p95 of 654 seconds and maximum 707 seconds. These are failed-run observations, not SSH execution times or a successful p95.

The Router parent, prepare, forward, restore and probe workflows declare a 600-second queue timeout on every step and at workflow level. This makes the existing pinned-serve default explicit. It does not claim to fix probe fan-out starvation. The prepare account, package and Caddy actions run through official SSH exec under coreutils `timeout` at 30, 600 and 300 seconds. HTTP and TLS workload probes each run under a 10-second coreutils timeout. Each official SSH execution timeout adds 30 seconds beyond its remote process bound, so its inherited 300-second limit cannot shorten the package phase.

The shared Route children own the execution bounds of their phases; this Router declaration cannot impose a process timeout inside another workflow.

The legacy spec is `6291bb036:apps/gateway/app/Actions/Clusters/SetClusterRouterAction.php`. Its ordered phases and proof readback are:

| Phase | Effect Node | Required proof |
| --- | --- | --- |
| Baseline/account/packages | Candidate Router | Correct admitted account, installed Caddy and managed ingress rules. |
| Router certificates | Candidate Router and affected workload Nodes | Node-local keys, exact admitted public CA and valid signed pair for every affected scope. |
| Firewall policy | Candidate Router and affected workloads | Managed member allowances; no unrelated policy withdrawal. |
| Workload verification | Candidate Router to each workload | TLS hostname and chain checks succeed before Caddy/DNS publication. |
| Router Caddy | Candidate and old Router | Loaded Caddy agrees with the admitted prepared/after catalog; candidate serves the proof hostname. |
| DNS publication and assignment | DNS participants and Gateway | Fresh authoritative answer selects the candidate; Gateway selects only the admitted assignment. |
| Old Router cleanup | Old Router and affected workloads | DNS grace finishes before old sites, scoped certificates and allowances are removed. |
| Return | Same participants with reversed selection | Separate normal Router set, terminal receipt and original serving/DNS selection restored. |

The native factory also retains its existing PHP and URL/environment publication steps. This timeout change adds no phase or changes their order. A forward failure and its restoration are failure evidence; they cannot count as a successful cutover and return.

The F13 topology is `["app-dev+ingress", "spare+router", "spare-2+router"]`, with a dev Cluster and the topology Gateway's normal CA/sign primitive. Lane 11 owns the catalog additions. Before admission, verify both Router candidates, their Caddy and DNS state, and the route/workload inventory. Capture the forward Operation/run and actual cutover readback before admitting the separate return. A successful pair supplies the first measured timing baseline. Production Cluster 9 is outside this proof.
