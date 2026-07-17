# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `.orbit/loop.md`
- Worktree: `/Users/nckrtl/.codex/worktrees/fix-s3-backend-dns`
- Branch: `codex/fix-s3-backend-dns`

## Goal

S3 backend hostnames resolve to active S3 nodes' WireGuard addresses instead of the router wildcard, allowing `s3.orbit` to reach SeaweedFS.

## Scope

- Owned: gateway dnsmasq rendering and focused DNS regression coverage.
- Constraints: only emit backend records for active S3-role nodes with matching canonical hostnames; preserve router-owned `.orbit` service routing.
- Out of scope: the 19 pre-existing fleet doctor issues and unrelated workload updater failures.

## Proof

- Verification:
  - focused: passed - RED reproduced the missing exact backend record and missing route-to-DNS persistence; GREEN DNS, S3 lifecycle, and client-bootstrap completion tests passed 108 tests/389 assertions
  - broader: passed - all nine repository units passed at the reviewed tip; receipt `.orbit/quality-gates/quality-check-2026-07-17T071737Z-cb71c8f1f6ad.json`
  - runtime: passed - the client-owned bootstrap API lifecycle regression proves a provisioning S3 node gains the exact backend DNS record after its terminal transition to active; live candidate verification follows landing
- Blast radius: complete - evidence=repository-wide search of `DnsmasqConfigBuilder`, S3 backend hostname construction, proxy route rendering, DNS reconciliation, and doctor consumers; result=canonical active S3-role backend names gain exact records before the generic router wildcard without changing other service routes
- Review: passed - independent review found no remaining renderer, lifecycle, transport, security, or documentation gaps; human-judgment=not-required
- Reviewed feature tip: 10341ef02b3dcce4271714c74cd86a08e5ff4bd0
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 10341ef02b3dcce4271714c74cd86a08e5ff4bd0
- Accepted main tip: 62150d794ce11128e129210d1f444db2c394973b

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`

Allowed states are `frame`, `build`, `prove`, `accept`, `accepted`, `land`, and
`blocked`. Review must be `passed`, `fix`, or `escalate`. Acceptance must be
`pending`, `accepted`, `not-applicable`, or `changes-requested`. A terminal
review PASS must record `human-judgment=required|not-required` and the exact
committed HEAD in `Reviewed feature tip`. Blast radius must be `not-required -
reason` or `complete - evidence=repository-wide search, inventory, or
lintable check; result=summary` before acceptance; `gaps` returns to BUILD.
