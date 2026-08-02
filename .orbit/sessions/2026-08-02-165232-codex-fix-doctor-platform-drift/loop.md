# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: none
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-fix-doctor-platform-drift`
- Branch: `codex/fix-doctor-platform-drift`

## Goal

Make Doctor distinguish real live drift from not-applicable checks and wrong-
machine probes: macOS unsupported self-route is not drift; host CIDR/family
firewall shapes are equivalent when live UFW matches; process selection follows
current app instance/workspace placement; gateway host-owned checks run on the
host boundary while container-owned checks stay local; local sqlite is not fleet
database drift; same-node Docker DB consumers use service alias/internal port.

## Scope

- Owned:
  - gateway Doctor process/database/firewall/proxy/tools probes and restore paths
  - shared firewall shape canonicalization
  - RemoteLocalExecutor gateway-host boundary for host-owned checks
  - product Doctor docs and test maps under `apps/docs/content/domains/**`
  - focused Pest coverage for the named cases
- Constraints:
  - work only in this prepared worktree
  - no live topology mutation
  - no `composer test:e2e*`
  - no merge, push, deploy, release, or worktree cleanup
  - preserve unrelated work
- Out of scope:
  - removing intentional gateway metrics/node-exporter intent
  - suppressing genuinely missing processes on their actual current node
  - LAND/merge

## Proof

- Verification:
  - focused: passed - Pest filters for self-route, firewall equivalence, placement, host boundary, sqlite not-applicable, postgres alias; isolated `FeatureFinalizationGateTest` 128 passed
  - broader: passed - exact clean receipt `.orbit/quality-gates/quality-check-2026-08-02T144925Z-a9e00c66d1c6.json` (exit 0, git dirty false, commit `870a749ed4e3cf5331d10fdbd18f68cf0d2eeab5`)
  - runtime: passed - automated real-surface for probe/transport correctness; evidence `.orbit/evidence/doctor-platform-drift-proof.txt`; no live topology mutation
- Blast radius: complete - evidence=repository-wide searches for process.node_id selection, self-route unsupported handling, firewall source/family matching, force_remote_host/gateway host execution, managed Docker DB endpoints, and transitional SSH inventory; result=shared FirewallRuleShapeCanonicalizer for detection+convergence, process placement via WorkspacePlacement, host-owned gateway checks force host boundary when containerized, sqlite local not fleet drift, postgres alias parity with mysql
- Review: passed - independent review found no actionable issues; human-judgment=not-required
- Reviewed feature tip: 870a749ed4e3cf5331d10fdbd18f68cf0d2eeab5
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 870a749ed4e3cf5331d10fdbd18f68cf0d2eeab5
- Accepted main tip: f6d18e0faf64e6023be593b16d31da09a89124e8

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
