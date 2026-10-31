# Native footprint network action

`network_direct.ts` exposes `convergeNodeNetwork(input, signal?)` for a native
Node converge root. It calls the one Fleet peer-DNS implementation locally and
returns a bounded artifact outcome. It does not read or project a Gateway
Operation, admit a child, choose a transport, generate a WireGuard key or render
the hub. It needs no private material.

The root reads its immutable `network` snapshot through the shared direct
client. A null snapshot skips dispatch. Before invoking the action, the root
checks that the snapshot Node matches the bound Operation Node and places the
step on that Node's worker with the matching Node label.

The strict v1 input contains:

- `schema_version: 1` and positive `node_id`.
- `agent_sha256`, exactly 64 lowercase hex characters.
- `peer_dns: {dns_server, public_key, domains}`. The DNS server is an IPv4
  address already validated against the WireGuard subnet by admission. The
  public key encodes 32 bytes in canonical base64. Routing domains form a
  nonempty, unique, sorted suffix set of at most 512 lowercase domain names,
  each at most 253 ASCII bytes.

Gateway admission uses the existing peer-DNS eligibility and policy renderer. It
does not run a remote inspection. The action rejects malformed snapshots; it
does not change them into another policy. The root's existing artifact plan
selects required work. Forced convergence still leaves a matching live policy
unchanged.

Fleet owns inspection, repair and verification. Its local commands use the
admitted agent digest and the configured Node user's sudo. Inspection takes at
most 10 seconds; repair uses the existing 120-second deadline. A repair happens
only for drift and must pass a fresh policy and uplink check before success.

Successful results contain `schema_version: 1`, `name: peer-dns`,
`status: applied|unchanged`, `policy: true` and `uplink: true`. Known terminal
refusals contain only the version, artifact name, `status: failed` and the
closed Fleet error code. Both shapes are under 256 encoded bytes. Inspection
strings and diagnostics never enter the root result.

The root maps cancellation to the step signal. Fleet checks it before a
mutation. Once a repair starts, its supervised command finishes and is verified
even after a late abort. Lost replies, malformed outcomes and unknown failures
throw to native workflow recovery. They do not become successful artifacts or
terminal refusals. The root alone projects the final Operation result.

This callable does not register a writer or change an existing caller. The
owning `node.converge` cutover composes it in the same root and removes obsolete
execution paths after its retained parent callers move. Full WireGuard
provisioning/removal, private-DNS listener installation and DNS catalog
publication have separate owners and contracts.

Focused checks:

```sh
deno test --allow-read extensions/behaviors/operations/node/network_direct_test.ts
deno test --allow-read extensions/behaviors/operations/fleet/peer_dns_test.ts
```
