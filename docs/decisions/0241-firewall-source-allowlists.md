---
title: "ADR 0241: Firewall source allowlists"
sidebarTitle: "0241 Firewall source allowlists"
description: "Superseded by ADR 0245. Admitted selected sources before broad WireGuard trust through a named firewall rule."
---

# ADR 0241: Firewall source allowlists

Give an operator one named source allowlist for a Node port or range. Place its source allows and final deny before broad WireGuard trust.

## Status

Superseded on 2026-11-01 by ADR 0245.

Plain allows and denies replace the allowlist: [0245](/decisions/0245-identify-firewall-rules-by-port-protocol-and-source) puts every operator rule above broad WireGuard trust and keeps the protected ports on deny. The migration turned each allowlist into a deny from anywhere plus one allow per source.

Principle: [One way, one name](/mission#principles) and [Security fits the real threat model](/mission#principles). One intent record owns the complete source set and its UFW block. The Gateway admits intent and projects results; Swamp executes it.

## Context

A deny after `orbit:wireguard-members` cannot restrict a WireGuard peer. Independent named allows and denies do not express a closed source set or its required order. Rex on port 7297 needs selected peers to connect while other peers are denied.

## Decision

Add `firewall:allowlist` and its API operation with a name, port or ordered range, optional protocol (default `tcp`), and at least one IPv4 address or CIDR. Normalize and deduplicate sources. Repeating a name replaces its source set as a whole. Port and protocol stay fixed for that name. Refuse overlapping named operator rules on the same Node and protocol whenever one is an allowlist. This prevents an ordinary write from replacing its terminal deny or deleting its source permits.

### Intent and execution

Reuse named firewall records, operation admission, per-Node firewall locks, result projection, listing and removal. Represent the action as `allowlist` with a separate source array. Existing single-source allow and deny rules retain their contracts.

Swamp applies a comment-owned block through the official SSH extension. Source allows precede a terminal port deny, and the complete block precedes other UFW rules. The official firewall configuration model was checked: it handles numeric tuples but has no rule-position or comment-owned block contract. A shipped Python action performs UFW inventory, block replacement and verification in one native phase. It adds no SSH transport or Gateway executor. On a source update it installs temporary denies before withdrawing old lines. It builds and verifies the new permits and final deny, then removes the temporary denies. Replacement cannot temporarily expose the port. Removal deletes every line with that exact owner comment.

### Protected ports and inspection

Refuse an allowlist intersecting SSH (port 22 and the Node's recovery port), the configured WireGuard port, or a bounded port in the Node's managed firewall catalog. Broad peer-trust rules with no port do not reserve every port. Protection ignores the requested protocol to preserve recovery paths. Management ports cannot be made private through this feature.

Native inspection expands each allowlist into its source allows and deny. Matching includes owner comment, full shape and relative order. Missing sources, stale source lines, wrong shapes, and a block behind broad trust are unhealthy. Doctor reuses the same native comparison for firewall configuration and rule lifecycle.

## Rejected alternatives

- Add independent operator allows and a deny: names do not bind a closed set, and the managed peer allow wins first.
- Narrow `orbit:wireguard-members` globally: changes unrelated Node connectivity.
- Execute UFW in the Gateway or CLI: duplicates Swamp's execution ownership.
- Extend the Rust agent: the agent observes; Swamp acts.

## Consequences

Operators can restrict a service reached over WireGuard without changing peer membership. Source changes are complete replacements. Removing the allowlist restores the rules beneath it, including peer trust. Existing connections can survive a UFW change through connection tracking; use a new connection to verify source denial. Code and native fixture tests do not prove fleet application. No Node is changed by this contribution.

## Affects

- Components: apps/gateway, apps/cli, packages/php-sdk
- ADRs: none.
- Detail: [Firewall commands](/cli/firewall).
- Verify: API validation and admission tests, rendering and ordering unit tests, native Swamp workflow fixtures, inspection comparisons, SDK transport and CLI contract tests.
