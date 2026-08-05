# Orbit Feature Loop

- Scratchpad: none
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-live-log-command-candidate`
- Branch: `codex/live-log-command-candidate`

## Goal

Preserve durable, sanitized no-GitHub live-candidate release evidence for the
landed Laravel runtime log command deployment (VERSION 0.1.190) after successful
live acceptance and frankenphp runtime promotion.

## Scope

- Owned: compact session archive of sanitized live-candidate evidence and
  candidate metadata via `bin/orbit-session-archive` from this worktree
- Constraints: no secrets or raw app/process log bodies; no tracked source
  edits; no touch of unrelated primary main tip `db7c9486`; no worktree cleanup
  unless archive is verified and official cleanup gates apply
- Out of scope: GitHub release/tag/assets; final gateway version tag; VERSION
  bump; doctor adopt/restore; `composer test:e2e*`

## Proof

- Verification:
  - focused: passed - release tests and secret scan
  - broader: passed - quality-check and final-check at `a92c01a95`
  - runtime: passed - candidate=20260805T171324Z-a92c01a95; venue=live-topology; environment=live; target=gateway+fleet; expected=update:all completed with candidate digests and log surface proof; observed=activity 454857 completed digest sha256:d33c9c7ad2f3afa81e8fb2668862cc59a353e7bf7f25f9af1da7eb080c9bb145 migrations done verification passed promote-runtime PASS; result=passed; evidence=`.orbit/release-evidence/2026-08-05-live-log-command-candidate/40-final-report.json`
- Blast radius: not-required - no-GitHub live candidate refresh evidence archive only
- Review: passed - human-judgment=not-required independent verification of live deployment already reported
- Reviewed feature tip: a92c01a958ea9dae08eca8e72b8b6c4b3e6eb0f8
- Acceptance venue: live
- Acceptance: accepted
- Accepted feature tip: a92c01a958ea9dae08eca8e72b8b6c4b3e6eb0f8
- Accepted main tip: a92c01a958ea9dae08eca8e72b8b6c4b3e6eb0f8

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`

## Release evidence citations (compact archive)

Exact regular files only:

- `.orbit/release-evidence/2026-08-05-live-log-command-candidate/MANIFEST.json`
- `.orbit/release-evidence/2026-08-05-live-log-command-candidate/00-failed-prior-attempt.json`
- `.orbit/release-evidence/2026-08-05-live-log-command-candidate/candidate.env`
- `.orbit/release-evidence/2026-08-05-live-log-command-candidate/40-final-report.json`
- `.orbit/release-evidence/2026-08-05-live-log-command-candidate/29-candidate-state.txt`
- `.orbit/release-evidence/2026-08-05-live-log-command-candidate/33-activity-show-454857-sanitized.json`
- `.orbit/release-evidence/2026-08-05-live-log-command-candidate/38-log-command-proof-sanitized.json`
- `.orbit/release-evidence/2026-08-05-live-log-command-candidate/37-post-doctor-summary.json`
- `.orbit/release-evidence/2026-08-05-live-log-command-candidate/37-post-doctor-delta.json`
- `.orbit/release-evidence/2026-08-05-live-log-command-candidate/37-doctor-promote-decision.json`
- `.orbit/release-evidence/2026-08-05-live-log-command-candidate/23-quality-check-artifact.json`
- `.orbit/release-evidence/2026-08-05-live-log-command-candidate/frankenphp-promotion.log`
- `.orbit/release-evidence/2026-08-05-live-log-command-candidate/19-rca-summary.json`
- `.orbit/quality-gates/quality-check-2026-08-05T171252Z-22116de640bd.json`

## Notes

- Failed prior candidate `20260805T162728Z-3da61146f` / activity 453501 preserved as
  evidence only; not promoted.
- Successful candidate `20260805T171324Z-a92c01a95`; live-test channel left selected.
- No GitHub release or final gateway version tag.
