---
name: implementing-features
description: Use when implementing an Orbit feature, bug fix, command behavior change, documentation correction, or project workflow improvement.
---

# Implementing Orbit Features

Own the requested result through `FRAME -> BUILD <-> PROVE -> ACCEPT -> LAND`.
`HARNESS.md` is the canonical loop contract; this skill is the compact route.
The orchestrating session (Codex or Claude) that the human started is the sole feature owner.
Workers run in the feature tmux session `feat-<slug>` created by `bin/orbit-prepare-worktree`; never create or use a Solo project.

## Non-Negotiable Boundaries

- Work in an isolated worktree created by `bin/orbit-prepare-worktree`. It seeds
  `.orbit/loop.md` when it is missing. If preparation fails, report the
  blocker; do not recreate the setup flow manually.
- Preserve unrelated user state.
- Never run, delegate, background, schedule, hook, script, or trigger a
  `composer test:e2e*` command; canonical rule: `HARNESS.md`.
- Keep product docs, executable coverage, and implementation aligned.
- Reject nonignored untracked files at review, acceptance, finalization, and
  sync boundaries.
- `human-judgment=required` work needs explicit user acceptance before merge.
  Do not rebase or amend an accepted feature tip.

## FRAME

1. Confirm exact checkout identity; route with `AGENT_FAST_PATH.md`.
2. Resolve outcome, owned paths, constraints, and exclusions against
   `PRODUCT_DECISIONS.md` and the owning `apps/docs/content/` authority.
3. Fill or update the seeded `.orbit/loop.md` Goal and Scope; raw feedback
   belongs in `.orbit/feedback.jsonl`. Stateful, lifecycle, or concrete UX
   work may append the `primitive=`/`transitions=` Scope clause per
   `HARNESS.md` FRAME.
4. With a stable scope, pull prior feedback:
   `bin/orbit-feature-feedback relevant --surface=<scope> --json`.
5. Derive the venue early: `bin/orbit-feature-acceptance route`.

## BUILD

Start with failing coverage in the owning framework; capture red, make the
smallest change, rerun. Load owning skills. macOS Agent: `tauri-agent-development`.

Dispatch substantive repository edits to Grok workers with `bin/orbit-worker-spawn --role=impl --cli=grok --brief=<path>`; Grok runs with no model override and cwd at the exact feature worktree. Do not substitute an owner subagent or direct owner implementation.
Wait for workers with `bin/orbit-worker-watch` in the background; read handoff files, never worker output; inspect a log only to diagnose a stalled or dead worker.
Missing tmux, grok, or claude on the machine is a blocker.

## PROVE

Run the narrowest relevant verification while building, then the diff-routed
broader gate: docs-only `composer docs-lint`; non-docs changes
`composer quality-check`; TTY/stream/liveness risk PTY capture; integrated
runtime behavior the real venue. `composer quality-gate:final-check` is an
evidence read; it must not rerun Pest or quality-check; missing comparable
timing means `timing analysis was skipped`.

When the Goal claims runtime reachability or convergence, proof must directly
exercise the claimed final outcome. A failed, excluded, still-required, or
deferred final hop means `Verification.runtime` cannot be recorded as
`passed`; stay in PROVE. For non-`automated` venues, record the structured
runtime receipt on the `Verification.runtime` row per `HARNESS.md` PROVE. A
same-candidate proof retry keeps Review and the reviewed tip; a reviewer FIX
resets them.

After focused checks pass, commit the candidate and confirm the worktree is
clean.
Spawn one fresh read-only Claude general reviewer per reviewed tip with `bin/orbit-worker-spawn --role=review --cli=claude --brief=<path>` (`claude --dangerously-skip-permissions --model opus` in the worktree).
The same general reviewer owns blast radius, ESCALATE, and terminal PASS or FIX.
FIX resets Review, reviewed tip, and Blast radius; return to Grok BUILD, prove,
commit, then spawn fresh Claude Opus.
On PASS record the exact reviewed HEAD and
`human-judgment=required|not-required`.

## ACCEPT

Arm acceptance after the diff-derived venue proof:
`bin/orbit-feature-acceptance ready --loop=.orbit/loop.md`. The venue table is
in `HARNESS.md` Acceptance Venues; `automated` surfaces (docs, tests,
repository tooling under `bin/`) still require the diff-routed
`composer quality-check`.

Run every deterministic acceptance command yourself. Do not hand the user a
mechanical command checklist; human acceptance covers only remaining judgment.
CLI retained topology proof runs in a user-attachable `proof-1` window of the feature tmux session; keep it open for the user only when `HUMAN_JUDGMENT: required`.
On `actor=automated`, run
`bin/orbit-feature-acceptance accept --loop=.orbit/loop.md --actor=automated`.
Do not send an acceptance handoff when the actor is automated. On
`actor=user`, prepare the experience, send one handoff, and record the
verbatim acceptance with `--actor=user --source-ref=<codex-or-claude-ref>`.

On feedback, use `bin/orbit-feature-feedback record`, invalidate acceptance,
return to BUILD, and repeat affected proof and review. Main movement requires
merge and PROVE. Close feedback through `HARNESS.md` Feedback And Protections;
Never solicit a waiver; record only a user-volunteered one.

## LAND

Prefer `bin/orbit-feature-land --branch=<feature> --worktree=<exact-feature-worktree>`
(`--status`/`--plan`/`--one-step` inspect or resume). Manual LAND follows
`HARNESS.md` LAND: lint with `bin/orbit-feature-finalization-check --lint .orbit/loop.md`,
validate each destructive mutation on `<exact command>`, execute after
`FINALIZATION: PASS`, then compact `bin/orbit-session-archive` from the feature
worktree (never cwd main; `--full` only for failure/escalation/security/release
scope). Commit archive/index, kill the feature tmux session (`tmux kill-session -t '=feat-<slug>'`, validated by `bin/orbit-feature-finalization-check`), remove the exact clean merged worktree, then delete the exact merged feature branch. Preserve unrelated tmux sessions and files. Report outcome, proof, review, accepted tips, archive, and blockers.
