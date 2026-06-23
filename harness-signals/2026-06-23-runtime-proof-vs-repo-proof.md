# Signal: Runtime Proof Is Different From Repo Proof

Status: recurring
First seen: 2026-06-22
Last seen: 2026-06-23
Last reviewed: 2026-06-23
Source worktree: one-time historical Codex backfill; doctor-issue-resource-labels
Source commit: none
Signal type: live-node
Guardrail target: HARNESS.md, .agents/skills/implementing-features/SKILL.md, .agents/review-personas/cli-command.md, release workflow
Guardrail change: current root routing and existing CLI retained-VM gate; source-launcher proof tightened in doctor-issue-resource-labels
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

One-time mini Codex-session backfill found 5 Orbit session records matching
runtime-proof or not-proven language. The strongest correction was session
`019ee688-485d-7303-b0d4-d017222cdf12`, where live user reproduction showed
v0.1.147 was not proven and the worker was told not to run live fleet
acceptance or update live nodes. Another CLI rendering session required proof
from tests and a live Solo terminal run on Mini before acceptance.

On 2026-06-23, the doctor issue-label retained VM gate initially ran bare
`orbit doctor --node=beast` inside a retained Incus VM. That command exercised
the installed `/usr/local/lib/orbit/orbit-binary` and old live-ish runtime
configuration, so it rendered the stale nested `ISSUE` table. The source-mounted
checkout already contained the new renderer. Re-running from
`/home/orbit/orbit-run` with `./apps/cli/orbit doctor --node=app-dev-1`
exercised the intended source overlay and produced the new resource-specific
bullets.

The same retained gate then exposed a second proof-shape issue: the Solo
terminal existed, but the command was launched as a host-wrapped
`ssh ... incus exec ... <orbit command>` one-shot. That produced a useful
transcript but did not let the user watch the in-progress CLI state in the VM.
The terminal had to attach to an interactive shell inside
`orbit-e2e-dev-3b9cb2-operator` at `/home/orbit/orbit-run` before running the
doctor command.

## Missing Guardrail

Agents could finish after repo-local tests without crossing the boundary to the
runtime surface that the user needed to trust.

The retained VM gate also lacked an explicit launcher-path check. A retained VM
can contain both the source-mounted checkout and an installed Orbit binary, and
those can point at different code and runtime configuration.

The gate also lacked an explicit interactive-terminal shape. For human renderer
work, a host-wrapped `incus exec` command can hide progress/liveness behavior
that the user needs to inspect.

## Guardrail Change

`HARNESS.md` now routes CLI, provisioning/live-node, and release work through
explicit retained-topology or live-node proof before deployment or handoff. The
implementation skill also requires the retained Incus VM Solo-terminal gate for
CLI command changes before live or release-candidate deployment.

The implementation skill and CLI reviewer persona now require retained VM
evidence to name the launcher path. Source-mounted topology proof uses
`./apps/cli/orbit` from `/home/orbit/orbit-run` unless the report proves
`/usr/local/bin/orbit` resolves to the source checkout. Release-candidate and
live-node proof still use the installed binary under validation.

The implementation skill and CLI reviewer persona now also require human
renderer or progress proof to start from an interactive shell inside the target
retained VM. Host-wrapped one-shot `incus exec` output remains acceptable for
machine transcripts, JSON capture, or fallback diagnosis, but not as the
operator-facing Solo-terminal inspection gate.

## Verification

`rg -n "source checkout launcher|launcher path|source-mounted retained|/usr/local/bin/orbit|./apps/cli/orbit|interactive shell inside|host-wrapped|one-shot" .agents/skills/implementing-features/SKILL.md .agents/review-personas/cli-command.md harness-signals/2026-06-23-runtime-proof-vs-repo-proof.md`
shows the source-launcher and interactive-terminal guardrails are reachable from
the feature workflow, reviewer persona, and this signal record.

## Reappearance Check

If an implementation report claims completion from repo tests while the bug is
reported on a runtime surface, if retained VM proof does not name the launcher
path, or if human-renderer proof uses only host-wrapped one-shot `incus exec`
output, keep this record `recurring` and tighten the matching reviewer persona
or release gate again.

## Curation Notes

This is a broad signal. Split it only if one runtime surface starts needing a
more specific guardrail. Mini backfill supports keeping the release/live-node
approval boundary strict: a live reproduction can invalidate repo-local proof.
