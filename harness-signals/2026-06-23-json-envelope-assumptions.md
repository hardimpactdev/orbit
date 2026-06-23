# Signal: JSON Envelope Assumptions Hide Real Command Results

Status: open
First seen: 2026-06-22
Last seen: 2026-06-23
Last reviewed: 2026-06-23
Source worktree: one-time historical Codex backfill
Source commit: none
Signal type: command-contract
Guardrail target: .agents/review-personas/cli-command.md or focused command-contract tests
Guardrail change: pending
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

Orbit has command tests, but the harness does not yet have a CLI reviewer
persona or deterministic command-contract guard that asks agents to inspect the
actual JSON shape before summarizing results.

## Guardrail Change

Pending. This should feed Slice 7, the first CLI command reviewer persona, or a
later deterministic command-contract test when the affected command contract is
clear enough.

## Verification

No guardrail is in place yet beyond this searchable signal record.

## Reappearance Check

If this affects another command implementation or release report, keep this
record `open` but add the command and result shape here, then prioritize the
CLI reviewer persona before broader automation.

## Curation Notes

This is intentionally not fixed in the first batch because the agreed first
batch stops before reviewer-persona infrastructure.
