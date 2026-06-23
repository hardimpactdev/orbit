# Signal: Release Boundaries Require Explicit Approval

Status: guarded
First seen: 2026-06-22
Last seen: 2026-06-23
Last reviewed: 2026-06-23
Source worktree: one-time historical Codex backfill
Source commit: none
Signal type: agent-mistake
Guardrail target: HARNESS.md, release workflow
Guardrail change: current root goal contract and release routing row
Related signals: harness-signals/2026-06-23-runtime-proof-vs-repo-proof.md
Superseded by: none
Tags: release, approval-boundary, live-node

## Signal

Release work can slide from candidate verification into merge, push, tag,
publish, or live fleet mutation if the approval boundary is not written down
before work starts.

## Prior Occurrences

Recent release-candidate and live-fleet sessions repeatedly needed explicit
separation between proving a candidate, deploying it for validation, and
performing final release actions.

## Missing Guardrail

The workflow had release gates, but the active goal contract did not force the
agent to name which release steps were approved and which required a fresh user
decision.

## Guardrail Change

`HARNESS.md` now includes `Human approval boundary` in the goal contract and a
release routing row that stops on unclear approval or any failed release gate.

## Verification

`rg -n "Human approval boundary|Release gates|approval boundary|tag, publish" HARNESS.md`
shows the boundary is visible from the root harness.

## Reappearance Check

If release work again crosses into unapproved merge, tag, publish, or live
mutation, mark this record `recurring` and tighten the release skill itself.

## Curation Notes

Keep until the release workflow has been exercised through several candidates
without approval-boundary confusion.
