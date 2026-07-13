# Orbit Feature Loop

- Scratchpad: /Users/nckrtl/shared-knowledge/projects/orbit/superpowers/2026-07-13-doctor-agent-drift-contracts.md
- Worktree: /Users/nckrtl/orbit/.worktrees/codex-doctor-agent-drift-contracts
- Branch: codex/doctor-agent-drift-contracts

## Goal

Eliminate false live doctor drift caused by stale Linux Agent service units,
shared logical operation-token consumption, and fixed-name Swarm gateway
container execution, then prove the exact candidate on the live topology.

## Scope

- Owned: local-executor operation-token identity, token consumption, gateway
  container resolution, Swarm gateway environment, fleet Agent systemd
  convergence, focused tests, and `execution-lanes.md` contract wording.
- Constraints: Agent push for all non-provisioning workload execution; preserve
  logical operation grouping; do not run E2E commands; do not publish a GitHub
  release, Git tag, or final version image tag.
- Out of scope: provisioning/bootstrap SSH, report-only home permission drift,
  obsolete Beast intent restoration, and unrelated doctor cleanup.

## Proof

- Verification:
  - focused: passed - 60 gateway tests / 352 assertions for the corrected token and Agent-envelope contract, 278 affected gateway tests / 1024 assertions, 14 CLI tests / 107 assertions, docs lint, PHP syntax, Mago format, and diff check.
  - broader: passed - `composer quality-check` and `composer docs-lint` at `008693e414d0`; all nine app/package lanes passed.
  - runtime: passed - retained Incus topology `dev-e9aba1` (`operator_gateway_agent`) with checkout roles `operator,gateway,agent`; Solo terminal `doctor-agent-drift-proof` / process 1071 at `/home/orbit/orbit-run`; provisioning-only bootstrap established the missing Agent service, the unit was deliberately changed to `ExecStart=/home/orbit/.local/bin/orbit-agent-stale`, and the source-mounted workload update rewrote it to `/home/orbit/.local/bin/orbit-agent` before deferred restart. The Agent remained active with candidate hash `a284dc1f07c4cbf95e50f9505c20b5da8176861312e66866e7dc871d6e4f7f38`. A subsequent `internal:executor:verify` Agent-push attempt succeeded with response id, operation-run id, and consumed-token id `ecb283b7-fe7a-4d30-a23b-6c1495a51587` while logical grouping remained `retained-e9aba1-single-agent-push`. Exact Solo commands inspected the unit/hash/launcher and ran `orbit doctor --node=agent-1 --stream-json`; that broader doctor surfaced only retained-substrate intent plus parallel TLS-proxy errors after the single-command proof had passed.
- Review: passed - independent general review; human-judgment=not-required; no findings; prior per-attempt Agent-envelope identity P1 closed.
- Reviewed feature tip: 008693e414d0b0a63d4f51025fe6439f6504a5a2
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 008693e414d0b0a63d4f51025fe6439f6504a5a2
- Accepted main tip: 5bf9906dd1f858b1c91f9ab42bb5fea46e537aae

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
