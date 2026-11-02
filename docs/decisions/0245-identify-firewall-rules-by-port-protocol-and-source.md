---
title: "ADR 0245: Identify firewall rules by port, protocol and source"
sidebarTitle: "0245 Firewall rule identity"
description: "In progress. A firewall rule is its port, protocol and source on a Node; operator rules sit above Orbit's managed rules, narrow first; the allowlist goes."
---

# ADR 0245: Identify firewall rules by port, protocol and source

A firewall rule on a Node is identified by what UFW enforces: its port, protocol and source. The name and the source allowlist go away.

## Status

In progress.

Supersedes [0241](/decisions/0241-firewall-source-allowlists). Decided by Nick on 2026-11-01.

Principle: [One way, one name](/mission#principles) and [Security fits the real threat model](/mission#principles).

## Context

Rule names were a second identity that people had to invent. The UFW comment carried the name so Swamp found its lines, but UFW does not match the comment. When a person edited the comment, Orbit lost the rule. The official firewall configuration model cannot write a comment, both protocols, or a position.

The allowlist existed because a deny below `orbit:wireguard-members` cannot restrict a WireGuard peer. Ordering every operator rule above Orbit's managed rules solves that with plain allows and denies, so a second rule kind is no longer needed.

## Decision

- `orbit firewall:allow|deny <port> --node=ID [--from=SOURCE] [--protocol=tcp|udp] [--label=TEXT]` and `firewall:remove <port> --node=ID [--from=SOURCE] [--protocol=tcp|udp]`. `firewall:allowlist` is removed.
- The port is one number or an ordered range. A missing protocol means both TCP and UDP; a missing source means anywhere. Both are part of the identity.
- Admission refuses a second rule with the same identity (`firewall.rule_exists`), an allow and a deny with the same identity (`firewall.rule_conflict`), and both protocols beside `tcp` or `udp` on the same port and source (`firewall.rule_overlap`).
- Overlap at a different scope is allowed, such as a deny on 5432 from anywhere with an allow on 5432 from one address.
- UFW stops at the first match. Every operator rule sits above Orbit's own `orbit:` rules, so a deny also applies to WireGuard peers. Among operator rules a narrower rule sits above a broader one, allow or deny alike: one address above a CIDR above anywhere. The rule script inserts each line at that position with `ufw insert` and verifies the order as well as the lines.
- A deny on a protected port is refused at admission with `firewall.protected_port`: SSH 22, the recovery SSH port, the WireGuard port, and the managed role ports.
- There is no atomic swap of a source set. The safe order is documented: allows before a deny, a new source before the old one is removed.
- `firewall:remove` removes the one rule that matches; it refuses an ambiguous match with `firewall.rule_ambiguous` and lists the matches.
- The label is optional text stored in Orbit. The UFW comment is `orbit` plus the label: printable characters, at most 64, quotes removed. Matching never uses the comment.
- Migration turns each allowlist into one allow per source and a deny from anywhere, copies each name into the label, and refuses when two rules share an identity.

Execution: one shipped script runs over official SSH from Node 209 for every allow, deny and removal: `assets → apply → accepted`. It replaces the official-model fan-out and the allowlist script. A removal leaves a line that a role-managed rule with the same tuple also needs.

## Rejected alternatives

- Keep the allowlist beside allow and deny: two rule kinds for one need, and its atomic swap needed its own script.
- Keep names as identity: people invent them, and UFW does not enforce them.
- Keep the official firewall model: it cannot write a comment, both protocols, or a position.
- Rewrite the whole ruleset on each change: replaces lines Orbit does not own and widens every change.

## Consequences

Rules are found by what they do, and one rule kind covers restriction. Lines that an allowlist wrote already have the order of its replacement rules, so no Node rewrite is needed. Changing a source set takes several steps; the documented order keeps the port closed between them.

## Affects

- Components: apps/gateway, apps/cli, packages/php-sdk, apps/web
- ADRs: supersedes [0241](/decisions/0241-firewall-source-allowlists).
- Detail: [Firewall](/cli/firewall#rule-identity).
- Verify: the fake-UFW script tests and the minimal-topology receipts for allow, deny, remove, an ambiguous remove, a deny against a WireGuard peer, and a refused deny on port 22.
