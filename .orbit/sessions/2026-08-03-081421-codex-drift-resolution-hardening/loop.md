# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: none
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-drift-resolution-hardening`
- Branch: `codex/drift-resolution-hardening`

## Goal

After every real non-dry-run Doctor restore or adopt mutation, re-probe the same
selected scope and treat that fresh observation as authoritative so completed
action receipts cannot hide remaining drift or produce false-healthy results;
sub-probe execution failures become explicit Unverifiable issues.

## Scope

- Owned: gateway Doctor resolution finalization (`DoctorReportRunner`,
  `DoctorFixController`), `NodeSecurityPostureProbe`, `ToolsProbe` agent-runtime
  inspection, Doctor product/technical docs for restore/adopt verification and
  the new issue keys, focused Pest coverage
- Constraints: no commit/merge/push/deploy; no `composer test:e2e*`; preserve
  unrelated work; keep public/API compatible except correcting false-healthy;
  one general resolution verification path (no
  `restoreRequiresVerification` gate/list); preserve richer per-family action
  annotations without letting them decide issue visibility
- Out of scope: whether `update:all` automatically runs or gates on fleet
  Doctor; broad DoctorReportRunner rewrite; interactive-mode redesign beyond
  shared restore/adopt verification

## Proof

- Verification:
  - focused: passed - 226 tests / 1500 assertions across DoctorReportRunner, DoctorRunController, DNS restore, NodeSecurityPostureProbe, ToolsProbe
  - broader: passed - `composer quality-check` with ORBIT_QUALITY_CHECK_CPU_BUDGET=2; exit 0; all subgates 0; git.commit=c23104e644b620a1482ff1366ef57a1f9e27129c; dirty=false; duration 243s; exact clean-tip artifact `.orbit/quality-gates/quality-check-2026-08-03T061304Z-65cfc40ff067.json`
  - runtime: passed - retained Incus dev-a82245, operator_gateway_app-dev, source-mounted gateway checkout; exact evidence `.orbit/evidence/retained-dev-a82245-doctor-resolution.txt`
- Blast radius: complete - evidence=bounded repo-wide searches of resolution entry points, both transport exception types, new issue keys, docs, CLI, SDK, and test fakes; result=no stale resolution APIs, all contracts aligned, all four independent-review findings corrected
- Review: passed - independent Fable general review and corrective re-review; no findings; human-judgment=not-required
- Reviewed feature tip: c23104e644b620a1482ff1366ef57a1f9e27129c
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: c23104e644b620a1482ff1366ef57a1f9e27129c
- Accepted main tip: f31b3813ee8e964dd427bcd321b8b3e05ad96db2

## Status

- State: accepted
- Blocker: none
- Feature tip: c23104e644b620a1482ff1366ef57a1f9e27129c

## Feedback

- Events: `.orbit/feedback.jsonl`

Allowed states are `frame`, `build`, `prove`, `accept`, `accepted`, `land`, and
`blocked`. Review must be `passed`, `fix`, or `escalate`. Acceptance must be
`pending`, `accepted`, `not-applicable`, or `changes-requested`. A terminal
review PASS must record `human-judgment=required|not-required` and the exact
committed HEAD in `Reviewed feature tip`. Blast radius must be
`not-required - reason` or
`complete - evidence=repository-wide search, inventory, or lintable check; result=summary`
before acceptance; `gaps` returns to BUILD. Proof files retained by the compact
archive must be cited as one exact inline-code path such as
`.orbit/evidence/retained-dev-a82245-doctor-resolution.txt`; prose, directories,
padded code spans, and partial paths are not proof citations.
