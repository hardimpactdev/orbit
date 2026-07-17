# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: Valkey WebSocket live migration router TLS follow-up
- Worktree: /Users/nckrtl/orbit/.worktrees/codex-fix-websocket-router-tls
- Branch: codex/fix-websocket-router-tls

## Goal

Make internally managed WebSocket proxy routes issue and restore their Orbit leaf certificates before Caddy reload.

## Scope

- Owned: proxy route TLS detection and restoration for tls.managed_by=internal, focused tests, retained topology proof, and live candidate recovery.
- Constraints: preserve ingress, ACME, and string tls=internal exclusions; no GitHub release, tag, or final image promotion.
- Out of scope: pre-existing unrelated live fleet drift and the unreachable beast executor.

## Proof

- Verification:
  - focused: passed - evidence=`.orbit/evidence/websocket-router-tls-proof.txt`
  - broader: passed - evidence=`.orbit/evidence/websocket-router-tls-proof.txt`
  - runtime: passed - evidence=`.orbit/evidence/websocket-router-tls-proof.txt`
- Blast radius: complete - evidence=bounded repository-wide fixer, probe, renderer, query, WebSocket registration, test, and proxy authority documentation search; result=no unresolved TLS-policy consumer or exclusion gap
- Review: passed - human-judgment=not-required
- Reviewed feature tip: cfd91f0f00511c793d584a302f53e081d4ca8bdf
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: cfd91f0f00511c793d584a302f53e081d4ca8bdf
- Accepted main tip: b3de1fafa86d33e2aacc672ada5d2d1ebd634099

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
concise reason` or `complete - evidence=repository-wide search, inventory, or
lintable check; result=concise summary` before acceptance; `gaps` returns to BUILD.
Proof files retained by the compact archive must be cited as one exact
inline-code path; prose, directories, padded code spans, and partial paths are
not proof citations.
