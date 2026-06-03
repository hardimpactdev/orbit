# Open question — `node.tld` / DNS-mapping change authorization cascade

Status: **OPEN — needs design decision.** Surfaced during the 2026-06-03 docs-drift
audit (finding B7). Not product authority; this is a session artifact tracking an
unresolved design question. Resolve via `command-designer` / `handling-feature-requests`,
then record the decision in `apps/docs/content/product-decisions.md` and align the
authority docs + command contracts.

## The coupling

`node.tld` is shared node-level state that drives three coupled things:
- the gateway-owned **DNS mapping** `*.{tld} → node.wireguard_address` (node family),
  served by the dnsmasq substrate on the `vpn` role (tool family / `dns` tool row);
- every **dev app URL** `{app}.{tld}` and **workspace URL** `{workspace}.{app}.{tld}`
  (`architecture.md:110,447`);
- the **proxy routes** that serve those hostnames (proxy family).

Change the tld (or adopt a hand-edited `dnsmasq.conf`) and every app/workspace URL on
that node moves, and their proxy routes must re-render.

## What the docs do today

- `node:update --tld` writes the node-level tld with **only `node:update` on that node**;
  downstream convergence "belongs to the node-family doctor path" (`1_node-update.md:146-148`).
  The app/workspace URL/route changes are an **unauthorized consequence**, not separately gated.
- DNS-mapping content drift is detected **and** restored in **two** families today —
  `node.vpn_dns_mapping_mismatch` (node) and `tool.dns_config_drift` (tool) check the same
  thing and both re-render. Architecture says these owners "do not overlap" (`architecture.md:166-181`).
- `doctor --family=tool --adopt` of `tool.dns_config_drift` writes `(node,tld,wg)` triples into
  **node-family** state (`tool-doctor.md:157-166`) — a tool-family command mutating node-family records.

## The open questions

1. **Family ownership.** Which family owns DNS-mapping *verify / restore / adopt*? Options ranged
   over in audit: keep both (status quo, overlapping); node owns mappings end-to-end + tool owns only
   dnsmasq container/port liveness (B-full); or node=adopt-only + tool=restore+adopt.
2. **Cascade authorization.** When a tld/DNS change relocates app & workspace URLs and their proxy
   routes, must the caller hold grants on those apps/workspaces/routes too — or is `node:update` on
   the node sufficient and the cascade an accepted consequence? Should you be able to relocate every
   app's URL on a node with only a node grant?
3. **Adopt cross-resource semantics.** If adopting a hand-edited mapping implies a new `node.tld`,
   does adopt cascade-update app/workspace state, and under whose authority?

## Why it was deferred

Resolving this means inventing an authorization model across node + app + workspace + proxy
families. That is a product/UX design decision, not a docs-drift fix; encoding a guess into the
contracts under the guise of the audit would be wrong. The audit leaves the current contracts
untouched for B7.

## Audit findings touched

- B7 (tool-doctor adopt → node DNS state): **deferred**, no edit.
- The node/tool dnsmasq detect+restore **overlap** (above) is part of the same design and is
  deferred with it.
- A4 (allow `node:update --tld` for agent) was approved separately and is independent of this
  cascade question — it only widens which roles may set tld, not the cascade authorization.
