# Direct Route publications

`route.caddy_converge` starts `operation-route-caddy` on its exact admitted
`orbit-node-ID` target with the matching string Node label. The workflow uses
`@nckrtl/route-publication`, model name `route-publication-node-ID`, method
`convergeCaddy`. Native locking covers the method's fresh bound Operation GET,
local pinned sudo `caddy.publish`, and result projection. The Gateway returns
public rendered data and projects status; it does not execute Caddy.

The public artifact is `input.caddy_publication`:

```ts
{
  node_id: number,
  user: string,
  agent_sha256: string,
  caddy: { content, version, listen_addresses, unserved }
}
```

A refused render instead supplies the same identity plus
`error_code: "caddy.render_failed", stage: "render"`. No configuration is
applied on a refusal. The content digest proves byte integrity; freshness comes
from reading inside the canonical model method after its native lock is
acquired. No full render belongs in pre-lock task inputs. The Gateway owns this
render through `CaddyPublicationPlan::forNode(Node)`; it performs no Node calls.

Other same-Node parents use method `publishCaddy` on that SAME resolved model:

```ts
{ operation_id: number, kind: string, node_id: number, run_id: string }
```

The method fetches the existing bound root GET, verifies the parent and payload
Node identities and unfinished status, honors cancellation before the effect,
and returns `{ready:true,result,version}`. It makes no result POST and starts no
child. The owning parent alone projects its final result. Process Vite callers
must commit the admitted allocation before this read so the current render
contains it. The local action export is `publishCaddy` from `routes/caddy.ts`;
calling that function alone does not acquire the native publication lock.
Foreign Node public reads and private-material access are unchanged.

The local agent verifies its admitted digest and supervises Caddy for 120s. It
validates the whole configuration, publishes/reloads, and restores on failure.
Only proven render or target validation failures expose a bounded stage. Lost
responses escape to native recovery; there is no custom receipt or retry loop.
Swamp retains its default locking, concurrency, waits and retention policies.

The four direct Route kinds use `swamp.direct_writers`; the removed writer
refuses admission. Their discovery entries and Gateway executor contracts are
deleted. The separate publication writer settings and environment variables are
removed. Rollback is a revert PR. No Caddy Gateway primitive or old
`/route-publications` relay remains. Private DNS still uses
`operation-route-publication` with its existing Gateway primitive and migration
placement; this Caddy slice does not switch that kind's local execution.

Release requires the exact workflow loaded and its existing Gateway principal
run/read grant verified, exact serving Node workers, and a disposable real
canary. The same-root contention proof must show lock acquisition -> fresh GET
-> local effect and preserve newer whole-Node configuration. Retained Gateway
builders do not yet share this native model; audit and migrate those callers
before claiming that every Caddy writer is coordinated. Source/unit tests are
not a live canary or activation approval.
