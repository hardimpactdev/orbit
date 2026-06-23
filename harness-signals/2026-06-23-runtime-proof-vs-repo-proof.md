# Signal: Runtime Proof Is Different From Repo Proof

Status: guarded
First seen: 2026-06-22
Last seen: 2026-06-23
Last reviewed: 2026-06-23
Source worktree: one-time historical Codex backfill
Source commit: none
Signal type: live-node
Guardrail target: HARNESS.md, .agents/skills/implementing-features/SKILL.md, release workflow
Guardrail change: current root routing and existing CLI retained-VM gate
Related signals: none
Superseded by: none
Tags: live-node, runtime-user, release-candidate, cli

## Signal

Several Orbit fixes were correct in the repository but still needed proof from
the installed runtime surface: the actual launcher path, runtime user, retained
VM, live gateway, or deployed node. Static tests alone did not prove that the
operator would see the fixed behavior.

## Prior Occurrences

The pattern appeared in launcher accessibility work, live `doctor` rendering,
gateway/runtime drift, and release-candidate validation. In each case, the
useful proof came from the surface that actually runs Orbit, not only from the
worktree.

## Missing Guardrail

Agents could finish after repo-local tests without crossing the boundary to the
runtime surface that the user needed to trust.

## Guardrail Change

`HARNESS.md` now routes CLI, provisioning/live-node, and release work through
explicit retained-topology or live-node proof before deployment or handoff. The
implementation skill also requires the retained Incus VM Solo-terminal gate for
CLI command changes before live or release-candidate deployment.

## Verification

`rg -n "retained Incus|live-node|Release gates|runtime" HARNESS.md .agents/skills/implementing-features/SKILL.md`
shows the proof boundary is reachable from root harness context and the feature
workflow.

## Reappearance Check

If an implementation report claims completion from repo tests while the bug is
reported on a runtime surface, mark this record `recurring` and tighten the
matching reviewer persona or release gate.

## Curation Notes

This is a broad signal. Split it only if one runtime surface starts needing a
more specific guardrail.
