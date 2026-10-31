# Native Route publications

Swamp applies Route effects on the admitted Node's exact `orbit-node-ID` target
with the matching string `node` label. Logical Nodes 1 and 18 use those same
selectors. Their proxy workers own transport; Route code has no special case.
The Gateway supplies public desired state and records the root result.

`@nckrtl/route-publication` uses the canonical model name
`route-publication-node-ID`. Each method reads fresh public data after acquiring
that model's native lock. Root identity stays truthful when the effect runs on a
foreign admitted participant. The artifact's `node_id` must match the placed
Node. Ended or cancelled parents refuse before any effect.

Same-root callers use these methods with
`{operation_id, kind, node_id, run_id, target_node_id?}`. `node_id` identifies
the root or placed participant; `target_node_id`, when supplied, identifies the
placed participant. The Gateway authorizes the actual caller against the
immutable participant list. These methods return their result without posting
the parent result or starting another workflow.

| Method            | Fresh public input                                 | Local effect                                                       |
| ----------------- | -------------------------------------------------- | ------------------------------------------------------------------ |
| `publishCaddy`    | `caddy_publication`                                | Validate, swap and activate the whole Caddy configuration          |
| `publishPhp`      | `php_publication`                                  | Reconcile all shared PHP pools, including an empty final set       |
| `publishDns`      | `private_dns_publication`                          | Validate and publish the DNS owner's records and requester catalog |
| `publishFirewall` | `ingress_firewall` and `caddy_publication.node_id` | Reconcile managed ingress rules                                    |

Lower functions are exported from `routes/caddy.ts`, `php.ts`, `dns.ts`, and
`firewall-local.ts`. Calling a lower function alone does not acquire the native
model lock. Public plans contain data only. `runLocalProgram` executes fixed
repository-owned Python with sudo, a deadline, bounded stdout and suppressed
stderr. No agent action or Gateway execution primitive runs these effects.

Caddy keeps its host publication lock, validation before swap, manual-file
backups, pruning and restoration after activation failure. PHP validates before
changing live pool files and restores them if activation fails. DNS uses the DNS
owner's host lock, verifies the loaded catalog digest and listener response, and
restores prior data and service state after a failed activation.

All UFW writers share `/run/lock/orbit-firewall.lock` for the complete observe,
delete, apply and confirm transaction. The lock is a root-owned regular file
opened without following symlinks. `convergeFirewall({desired,remove,enable})`
owns managed rule reconciliation. `convergeRouteLan` and `removeRouteLan` own
only the exact admitted Route comment. Router-LAN extends this same engine.

`verifyLocal` in `routes/verification.ts` runs bounded OpenSSL or curl probes.
TLS checks both the admitted hostname and certificate trust. Agent observation
endpoints remain separate read-only interfaces.

Standalone Caddy uses `operation-route-caddy` / `convergeCaddy`. Standalone DNS
uses `operation-route-publication` / `publish`. Standalone LAN removal uses
`operation-route-lan-remove` / `removeLan`. Each uses exact Node placement and
projects only its own root. Native history is the execution record; there are no
claims, child Operations or keyed receipts.

For `cluster.router_set`, selecting the sole already-active Router records success
without host effects. The native Router DAG skips edge Caddy, DNS publication and
withdrawal checkpoints when admission marks the selection unchanged. It still
reads the admitted inventory and projects the parent result.

Router checkpoint and completion updates include `started_at`, as the shared
Gateway status request requires. Completion also includes `finished_at`. The
Gateway preserves the Operation's first start time.
