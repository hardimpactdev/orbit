# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `codex://threads/019fa8f4-96e7-70d3-b9a0-e52f607f84df`
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-sqlite-fixed-runtime`
- Branch: `codex/sqlite-fixed-runtime`

## Goal

Orbit-provided host PHP 8.5 and app/workspace FrankenPHP runtimes embed an
SQLite release fixed against the WAL-reset corruption race, proven through
both `SQLite3::version()` and `select sqlite_version()` locally and in Hauzer
production.

## Scope

- Owned: Orbit PHP CLI artifact recipe, FrankenPHP runtime image recipe,
  runtime contract documentation, artifact assertions, release-candidate
  rollout, and runtime version evidence.
- Constraints: preserve unrelated Orbit work; do not edit Hauzer; use
  release-candidate artifacts throughout the topology; do not publish a GitHub
  release; do not enable WAL until both runtimes are proven fixed.
- Out of scope: Hauzer application/configuration changes, enabling WAL itself,
  and unrelated Orbit runtime upgrades.

## Proof

- Verification:
  - focused: passed - gateway 54 tests/423 assertions; CLI 18 tests/129 assertions; docs lint, bash syntax, diff check, and secret scan passed; evidence `.orbit/evidence/sqlite-runtime-build-proof.txt`
  - broader: passed - composer quality-check; profile `.orbit/quality-gates/profiles/2026-07-29T08-56-03Z-293dd7e252cd`
  - runtime: passed - official SQLite 3.44.6 source identity, native macOS arm64 and Linux x86_64 PHP 8.5.8 artifacts, and FrankenPHP 8.5.7 image verified through SQLite3 and PDO surfaces; evidence `.orbit/evidence/sqlite-runtime-build-proof.txt`
- Blast radius: complete - evidence=repository-wide inventory of PHP runtime catalogs, installers, renderers, release manifest generation, workload update and verification, E2E mirrors, docs, and tests; result=PHP 8.5 moves alone to image major 2, candidate digests bind locally before exact accepted-build promotion, and 8.4/8.3 remain on major 1
- Review: passed - human-judgment=not-required; no findings at exact committed tip
- Reviewed feature tip: 293dd7e252cddcdda396be973a1b664a7d9cc80c
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 293dd7e252cddcdda396be973a1b664a7d9cc80c
- Accepted main tip: 0c0de11df0e1d9bbf19a318d286c9f67172d382a

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
a documented reason` or `complete - evidence=repository-wide search, inventory, or
lintable check; result=a concrete summary` before acceptance; `gaps` returns to BUILD.
Proof files retained by the compact archive must be cited as one exact
inline-code path, for example `.orbit/evidence/runtime-proof.txt`; prose,
directories, padded code spans, and partial paths are not proof citations.
