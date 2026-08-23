# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Session: feat-codex-firewall-safe-match
- Worktree: /Users/nckrtl/orbit/.worktrees/codex-firewall-safe-match
- Branch: codex/firewall-safe-match

## Goal

Make UFW firewall convergence preserve unrelated same-port rules and place new allow rules before broad deny rules.

## Scope

- Owned: UFW rule placement, stored-rule observation, exact ownership matching, focused tests, and firewall product docs.
- Constraints: Preserve protected WireGuard SSH access and unrelated user rules. Run Incus only through Beast. Do not run human-only E2E lanes.
- Out of scope: Non-UFW backends, unrelated firewall policy changes, GitHub release publication, and the dirty Beast primary checkout.

## Proof

- Verification:
  - focused: passed - gateway 62 tests/319 assertions; CLI 7 tests/11 assertions; Librarian lint 0 errors with 229 existing warnings
  - broader: passed - composer quality-check profile 2026-08-23T07-52-22Z-3b135ec347c8
  - runtime: passed - candidate=3b135ec347c87687b00d50f23e3c20015e549e97; venue=retained-incus; environment=dev-fixture; target=orbit-proof-firewall-673bd45; expected=replace only name-owned managed drift, preserve unrelated protected same-port allow, and place the new allow before the broad deny; observed=exact candidate gateway probe selected orbit:private-api, candidate apply deleted and replaced only that rule, protected allow survived, and final allow order was managed then protected then deny; result=passed; evidence=`.orbit/evidence/firewall-safe-match-retained-incus.txt`
- Blast radius: complete - evidence=Claude repository-wide inventory of every managed firewall-row producer and UFW writer; result=backend comment identity and allow-over-deny precedence are aligned across all affected surfaces
- Review: passed - Claude round 3 closed all prior findings; no new findings; human-judgment=not-required
- Reviewed feature tip: 3b135ec347c87687b00d50f23e3c20015e549e97
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 3b135ec347c87687b00d50f23e3c20015e549e97
- Accepted main tip: e950dbf73542278f2c7d1f6588d44bac0e05b249

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
