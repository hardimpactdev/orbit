---
title: "Route LAN firewall"
description: "How Orbit converges shared Node firewall tuples for Route LAN and public Ingress traffic over pinned SSH."
---

# Route LAN firewall

Orbit manages Node firewall rules for Route LAN hops and public Ingress (`80/tcp` and `443/tcp` with `source: any`) as shared `(port, protocol, source)` tuples rather than per-Route rules.

## How shared tuples work

Orbit derives unique `(port, protocol, source)` rules from each Node's complete site catalog:

- **Route LAN:** allows `443/tcp` from the Cluster Router's LAN address to each workload Node without destination IPs or Route comments. A missing LAN hop fails publication without falling back to WireGuard.
- **Public Ingress:** applies `80/tcp` and `443/tcp` with `source: any`, tracked separately from source-specific LAN rules.
- **Deduplication:** multiple Routes needing the same tuple share one rule. Removing the last Route using that tuple removes the rule.

## Convergence order

During Route creation, target changes, and Router transitions, Orbit observes live UFW state over pinned SSH. It applies only the needed tuple changes and verifies the resulting rules:

- **Replacement:** adds the union of old and new tuples first, then removes unused tuples after Caddy publication and the private DNS grace window.
- **Withdrawal:** removes tuples absent from the desired catalog after withdrawing the Route's Caddy sites. Orbit never deletes firewall rules it did not create.
- **Restore:** reapplies the captured prior tuple set if a transition fails before cutover.
