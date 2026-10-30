# Local peer DNS reconciliation

`extensions/behaviors/operations/fleet/peer_dns_local.ts` exports the shared
local action for Fleet and Node workflows. It has no Gateway client, Operation
projection or workflow admission.

```ts
reconcilePeerDns(input, { signal, beforeEffect, invoke });
```

`input` contains the admitted `node_id`, `agent_sha256`, `server`, `dns_server`,
`public_key` and `domains`. The server fields must match. The root validates
placement against its admitted Node before calling this action. `invoke` has the
exported `NodeCallFunction` type and defaults to the fixed local `runNodeAgent`
primitive.

The action inspects policy, repairs drift once, then verifies it. It returns
`{ result, changed }`, where `result` is an `Inspection` or
`{ error_code: ClosedPeerDnsRefusal }`. `changed` becomes true only when repair
returns a successful typed reply; it stays true if verification then returns a
bounded refusal. Unknown or malformed replies and transport failures throw to
native recovery. Refusals contain no diagnostic output.

`beforeEffect` runs immediately before repair, or before completing a matching
read-only inspection. A parent may use it to check cancellation. The signal also
stops pre-effect work. Once repair succeeds, verification runs without the
signal so a late abort cannot hide an applied effect. A parent must not turn an
uncertain mutation exception into cancellation.

The Fleet behavior supplies its existing Gateway cancellation reread and
converts only aborted read-only inspections to the shared no-effect cancellation
sentinel. Its writer selection and projected result remain unchanged. Node
callers can summarize the safe result without copying reconciliation or
importing Gateway code.
