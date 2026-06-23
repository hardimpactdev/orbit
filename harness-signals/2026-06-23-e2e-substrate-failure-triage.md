# Signal: E2E Substrate Failures Need Triage Before Code Blame

Status: guarded
First seen: 2026-06-22
Last seen: 2026-06-23
Last reviewed: 2026-06-23
Source worktree: one-time historical Codex backfill
Source commit: none
Signal type: e2e-failure
Guardrail target: .agents/skills/implementing-features/SKILL.md, HARNESS.md
Guardrail change: existing E2E readiness guidance and current root routing
Related signals: none
Superseded by: none
Tags: e2e, incus, docker, provider-pool

## Signal

Historical E2E runs exposed substrate failures that looked like product
failures at first glance: unavailable Docker capacity, Incus provider setup,
SSH/auth drift, missing bootstrapped dependencies, or shared lease-pool waits.

## Prior Occurrences

This pattern appeared in launcher, retained topology, and live-node work where
the failing command was meaningful only after separating code behavior from
provider or environment readiness.

## Missing Guardrail

Agents could treat every E2E failure as an implementation regression instead of
first classifying provider pool, bootstrap, auth, and topology readiness.

## Guardrail Change

The implementation skill already documents E2E readiness, shared provider
pools, and retained Incus inspection. `HARNESS.md` now routes provisioning and
live-node work through the testing authority docs and requires topology/node
evidence.

## Verification

`rg -n "E2E Readiness|provider pool|retained topology|Prepared-topology|composer e2e:preflight" HARNESS.md .agents/skills/implementing-features/SKILL.md`
shows the triage path is discoverable.

## Reappearance Check

If agents still misclassify substrate failures, move this from documentation
into Slice 8 by adding agent-native failure hints to the E2E preflight or lane
failure output.

## Curation Notes

Keep as a candidate input for the future failure-hint slice.
