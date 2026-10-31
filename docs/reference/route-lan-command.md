---
title: Route LAN command
description: Remove one withdrawn Route allowance with a placed native command.
---

`route.lan_remove` captures the withdrawn Route ID, workload Node ID and destination LAN IPv4 address at admission. The Gateway supplies this public intent to `operation-route-lan-remove`; the step makes no Gateway calls and needs no agent pin or secrets. The shared terminal reader projects `withdrawal/remove-lan` into the owning Operation.

The single placed command reads `ufw status numbered`, selects the exact destination and `orbit:route-<route_id>-lan` comment, and deletes matching HTTPS allowance numbers in descending order. It shares the canonical UFW host transaction lock `/run/lock/orbit-firewall.lock`, then verifies absence. Other destinations, Routes and comment suffixes survive. Inactive UFW, malformed owned rules and unsuccessful commands fail without exposing UFW diagnostics. Re-running after successful withdrawal converges without changes.

The official `@swamp/cfgmgmt` firewall model, version `2026.10.27.1`, has no destination-IP or comment selector. Its absence check does not recognize Orbit's destination-scoped Route rules. The native command covers this narrow gap; it does not replace firewall convergence or add a workflow lock. The capability fixture and retained native run are recorded in `/home/nckrtl/swamp-execution/evidence/lane8-official-firewall-capability.json`.

A parent withdraws host catalogs before admitting this action. Certificate removal stays separate. Its owner checks fresh catalogs to protect certificates still in use. This action changes no Analytics, Router LAN, DNS or certificate policy.
