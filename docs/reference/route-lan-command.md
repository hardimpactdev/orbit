---
title: Route LAN firewall
description: Converge shared Node source tuples through official pinned SSH.
---

The same pinned tuple child also applies the admitted public Ingress TCP80/443 rules, with explicit `source: any` and migration disabled. Its observation and verification distinguish these rules from source-specific LAN rules.

Route LAN allows TCP443 from the recorded Router LAN source to the workload Node. The Router still connects to the workload's recorded LAN address. The firewall rule has no destination or Route comment. Route sites already bind both LAN and WireGuard; WireGuard-only control sites keep their Caddy VPN-client guard. A missing configured LAN hop still fails publication without a WireGuard fallback.

`NativeRouteLanInput::forSites(Node, Collection)` derives unique `(port, protocol, source)` tuples from the complete published or prospective site catalog. `changes(before, desired)` keeps every required tuple present and removes only tuples absent from the desired set. Two Routes sharing a tuple share one allowance. Withdrawal of the first keeps it; withdrawal of the last removes it. Node identity and rule intent remain distinct from execution placement.

All steps run on Node209 with one target selector. `route-lan-apply` uses official `@swamp/ssh` with the existing identity-file locator and the exact admitted host keys. It observes active UFW state, shapes only the needed tuple commands, applies each tuple in its own native step and observes the result before migration. Pure typed metadata verifies every requested ensure state and each action outcome. No step reads the Gateway. Replacement first ensures the union of old and new tuples, then retires unused tuples after publication and the approved private DNS grace. Restore selects the captured prior set.

Official cfgmgmt 2026.10.27.1 was checked: its firewall model cannot accept admitted host keys and defaults to accept-new. Official SSH enforces the pins; the tuple fields and UFW command shape remain the same. There is no custom SSH transport, host-key store or rule engine.

The orchestrator approved this migration once on each Node. After official tuple convergence succeeds, `route-lan-migrate` uses the existing numbered-rule deletion action to remove every old `orbit:route-<id>-lan` rule. Adds precede deletes, so needed traffic has no gap. The action matches exact Route tags and preserves unrelated rules. The existing UFW transaction lock remains only for this migration. A failed add or an unproven observed tuple skips migration; failed deletion cannot report success. Terminal projection records `route.lan_shape=source-v1` in the existing Node-scoped settings store after proven cleanup. Later captures skip migration. Failed roots can safely repeat the idempotent cleanup until projection succeeds.

Route creation and target changes use the shared tuple child. They do not add Route-commented rules. Retained offline residue cleanup removes only its captured Route's exact comment; it cannot create or replace old allowances.

`route.lan_remove` admits the full desired tuple plan after its Route's host catalogs are withdrawn. `operation-route-lan-remove` composes the same child and emits one public JSON object at the fixed `withdrawal/remove-lan` terminal selector. Successful results include the migrated Node IDs; the shared reader owns projection. It needs no private material or agent action.

Roll out one Node first, then the remaining Nodes through the owning operator path. The runtime hold still applies until Platform proves current readiness. After all Nodes and Route writers use the new shape, remove the temporary migration workflow, asset and applicable gate exception in a follow-up PR. The destination audit and Caddy regression results are recorded in `/home/nckrtl/swamp-execution/evidence/route-lan-destination-audit-20261031.md`.
