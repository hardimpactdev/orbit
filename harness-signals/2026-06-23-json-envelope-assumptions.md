# Signal: JSON Envelope Assumptions Hide Real Command Results

Status: guarded
First seen: 2026-06-22
Last seen: 2026-06-23
Last reviewed: 2026-06-23
Source worktree: one-time historical Codex backfill
Source commit: none
Signal type: command-contract
Guardrail target: .agents/review-personas/cli-command.md
Guardrail change: current CLI reviewer persona slice
Related signals: harness-signals/2026-06-23-runtime-proof-vs-repo-proof.md
Superseded by: none
Tags: cli, json, command-contract, reviewer-persona

## Signal

Agents repeatedly risked reading command JSON as a flat payload when important
results were nested under fields such as `success.data.*`. That can make a
passing command look incomplete or hide the exact node, version, or activity
evidence needed for handoff.

## Prior Occurrences

The pattern appeared in release and `update:all` validation, where the useful
proof lived inside nested machine-readable response data rather than top-level
keys.

## Missing Guardrail

Orbit has command tests, but the harness did not yet have a CLI reviewer
persona that asks agents to inspect the actual JSON shape before summarizing
results.

## Guardrail Change

`.agents/review-personas/cli-command.md` now requires reviewers to inspect the
actual nested JSON shape before summarizing evidence, and to keep human and JSON
renderer proof separate.

## Verification

`rg -n "success.data|JSON Output|CLI Command Reviewer" .agents/review-personas/cli-command.md harness-signals/2026-06-23-json-envelope-assumptions.md`
shows the reviewer path and this signal record agree.

## Reappearance Check

If this affects another command implementation or release report, mark this
record `recurring`, add the command and result shape here, and consider a
deterministic command-contract test for the affected command.

## Curation Notes

Guarded by the first CLI reviewer persona. Keep open for future deterministic
sensor work only if the issue recurs.
