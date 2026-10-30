# Direct Route publications

`operation-route-publication` receives one admitted Operation ID, kind and Node
ID from the Gateway's native `workflow.run` request. When selected, direct Caddy
convergence and private DNS publication run without discovery or an Operation
claim.

One behavior instance per Node serializes render, publication and result
projection with Swamp's built-in behavior lock. These convergent primitives can
run again when a Swamp step is retried. No assignment, invocation or effect
receipt resource is stored by this workflow. Native run history and retention
use Swamp defaults. The Gateway keeps intent, validation and result projection.

Load the workflow before deploying the dormant Gateway endpoints. Keep scheduled
Route discovery and its previous workflow until production proof succeeds.
Gateway per-kind settings `ORBIT_ROUTE_CADDY_WRITER` and
`ORBIT_ROUTE_PRIVATE_DNS_WRITER` default to `polled`; `direct` starts this
workflow. The Gateway stamps the selection on admission, so discovery skips
direct Operations and existing polled Operations remain claimable after a
setting change. Rollback selects the previous Swamp path for new Operations,
never PHP.

After the active nckrtl.com cutover Operations end, run a private dev canary
with a request-local direct setting through the owning Gateway action. Verify
native run success and Gateway result projection before flipping the shared
per-kind setting. The workflow has one behavior step, with no approval or
`wait_for_signal` steps; native run-implied approve and signal permissions are
inert. Re-check the credential grant if either step type is added.

The next slice replaces Route parent composition and its remaining child waits.
This slice does not claim that the whole family has finished migration.

Before measurement on 2026-10-30, Gateway revision `1acd03bd0a44`:

| Operation | Kind                      | Admission to first start | Attempts | Terminal result    |
| --------- | ------------------------- | -----------------------: | -------: | ------------------ |
| 3868      | route.create              |                     51 s |        3 | succeeded          |
| 3895      | route.private_dns_publish |                    894 s |        3 | succeeded          |
| 3942      | route.shared_php_withdraw |                    261 s |        2 | succeeded          |
| 3943      | route.workload_firewall   |                    259 s |        2 | succeeded          |
| 3978      | route.update              |                     78 s |        1 | waiting at capture |

These records contain no failed Gateway steps. Attempts are claims, not failure
counts. Operation 3895 also has confirmed Swamp ENOSPC and worker disconnect
evidence; its DNS effect succeeded and recovery did not repeat publication. Do
not count an unfinished Operation as a success or infer a target failure from a
claim count. Evidence is in the operator's
`swamp-execution/evidence/routes-direct-20261030` directory. After release,
record admission, native run start, primitive start/end and result projection
times on Node 209, and classify failures separately as admission, Swamp,
transport, target primitive or projection.
