---
name: implementing-features
description: Use when implementing an Orbit feature, bug fix, command behavior change, documentation correction, or project workflow improvement.
---

# Implementing Orbit Features

Own the requested result through `FRAME -> BUILD <-> PROVE -> ACCEPT -> LAND`.
`HARNESS.md` is the canonical loop contract; this skill is the compact route.
Open only the section for the current state.
The current feature owner may implement directly.
Use workers only when useful: bounded slices with exact scope and checks.

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

Start behavior changes with failing coverage in the owning framework (Pest for
PHP/Laravel; native frameworks elsewhere); capture the literal red result,
make the smallest change, rerun. Domain skills — commands:
`command-designer` + `orbit-cli-development`; Laravel/PHP: Spatie + Pest;
shared contracts: `orbit-core-development` / `orbit-sdk-development`;
docs/Librarian: `librarian` + `orbit-docs-development`;
macOS Agent: `tauri-agent-development`.

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
clean; gates and review bind the committed HEAD. Use one fresh reviewer from
`.agents/review-personas/general.md`. Blast-radius closure and ESCALATE
answers stay with that same general reviewer, which issues the
terminal PASS or FIX. On FIX: record `Review: fix`, reset
`Reviewed feature tip: none` and
`Blast radius: pending`, return to BUILD, commit the proven delta, re-review.
On terminal PASS record the exact reviewed HEAD and
`human-judgment=required|not-required`.

## ACCEPT

Arm acceptance after the diff-derived venue proof:
`bin/orbit-feature-acceptance ready --loop=.orbit/loop.md`. The venue table is
in `HARNESS.md` Acceptance Venues; `automated` surfaces (docs, tests,
repository tooling under `bin/`) still require the diff-routed
`composer quality-check`.

Run every deterministic acceptance command yourself. Do not hand the user a
mechanical command checklist; human acceptance covers only remaining judgment.
For retained Incus, use one agent-owned Solo terminal at
`/home/orbit/orbit-run`. Keep it open for the user only when
`HUMAN_JUDGMENT: required`. On `actor=automated`, run
`bin/orbit-feature-acceptance accept --loop=.orbit/loop.md --actor=automated`.
Do not send an acceptance handoff when the actor is automated. On
`actor=user`, prepare the experience, send one handoff, and record the
verbatim acceptance with `--actor=user --source-ref=<codex-or-solo-ref>`.

On feedback: `bin/orbit-feature-feedback record`, invalidate acceptance, fix
in BUILD, resync what changed, repeat affected proof and review, return the
surface. If main advances, merge it in and return through PROVE; any HEAD
change invalidates prior acceptance. Close actionable feedback via the
`HARNESS.md` Feedback And Protections ladder before ACCEPT or LAND. Never
solicit a waiver; record only user-volunteered ones with source ref and
verbatim message.

## LAND

Prefer the resumable coordinator from primary `main`:
`bin/orbit-feature-land --branch=<feature> --worktree=<exact-feature-worktree>
--solo-project-id=<session-owned-id>` (`--status`/`--plan`/`--one-step`
inspect or resume). Manual LAND follows `HARNESS.md` LAND exactly: lint the
packet with `bin/orbit-feature-finalization-check --lint .orbit/loop.md`,
validate every destructive mutation via the same check on `<exact command>`;
execute only after `FINALIZATION: PASS`, then run the now-landed compact
`bin/orbit-session-archive` from the feature worktree (never cwd main;
`--full` only for failure/escalation/security/release scope) and commit the
archive/index before the ordered cleanup. Then report outcome, acceptance
surface, verification, reviewer verdict, accepted feature/main tips, archive,
and unresolved blockers.
