---
title: Route LAN firewall
description: Converge shared Node source tuples through official pinned SSH.
---

The same pinned tuple child also applies the admitted public Ingress TCP80/443 rules, with explicit `source: any`. Its observation and verification distinguish these rules from source-specific LAN rules.

Route LAN allows TCP443 from the recorded Router LAN source to the workload Node. The Router still connects to the workload's recorded LAN address. The firewall rule has no destination or Route comment. Route sites already bind both LAN and WireGuard; WireGuard-only control sites keep their Caddy VPN-client guard. A missing configured LAN hop still fails publication without a WireGuard fallback.

`NativeRouteLanInput::forSites(Node, Collection)` derives unique `(port, protocol, source)` tuples from the complete published or prospective site catalog. `changes(before, desired)` keeps every required tuple present and removes only tuples absent from the desired set. Two Routes sharing a tuple share one allowance. Withdrawal of the first keeps it; withdrawal of the last removes it. Node identity and rule intent remain distinct from execution placement.

All steps run on Node209 with one target selector. `route-lan-apply` uses official `@swamp/ssh` with the existing identity-file locator and the exact admitted host keys. It observes active UFW state, shapes only the needed tuple commands, applies each tuple in its own native step and observes the result. Pure typed metadata verifies every requested ensure state and each action outcome. No step reads the Gateway. Replacement first ensures the union of old and new tuples, then retires unused tuples after publication and the approved private DNS grace. Restore selects the captured prior set.

Official cfgmgmt 2026.10.27.1 was checked: its firewall model cannot accept admitted host keys and defaults to accept-new. Official SSH enforces the pins; the tuple fields and UFW command shape remain the same. There is no custom SSH transport, host-key store or rule engine.

The one-time migration that removed the old `orbit:route-<id>-lan` and `orbit:router-lan-*` rules is complete. On 2026-11-02 no Cluster member kept such a rule, so its workflow, script and `route.lan_shape` setting are gone (rule 22). The tuple child never deletes a rule it did not shape.

Route creation and target changes use the shared tuple child. They do not add Route-commented rules. Retained offline residue cleanup removes only its captured Route's exact comment; it cannot create or replace old allowances.

Route parents pass the captured desired tuple plan directly to `route-lan-apply` after withdrawing the Route's host catalogs. The child verifies the final tuples before its parent continues. LAN withdrawal starts no separate Operation and needs no private material or agent action.

The destination audit and Caddy regression results are recorded in `/home/nckrtl/swamp-execution/evidence/route-lan-destination-audit-20261031.md`.
