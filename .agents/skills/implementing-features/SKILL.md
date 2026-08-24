---
name: implementing-features
description: Run the Sol-owned native Luna sliced feature flow with one Claude review.
---

# Implementing Orbit Features

Own the requested result through `FRAME -> repeated BUILD <-> SLICE PROVE -> CHECKPOINT -> FEATURE PROVE -> REVIEW -> ACCEPT -> LAND`.
`HARNESS.md` is the canonical loop contract.
Sol owns one feature/worktree with one or more mandatory dependency-aware vertical slices and all session artifacts. Native Luna slice children use Codex collaboration; the feature tmux registry is only for the one Claude reviewer and retained proof terminal after all slices.

## Non-Negotiable Boundaries

- Work in an isolated worktree created by `bin/orbit-prepare-worktree`. It seeds `.orbit/loop.md` when it is missing. If preparation fails, report the blocker; do not recreate the setup flow manually.
- Never run, delegate, background, schedule, hook, script, or trigger a `composer test:e2e*` command; canonical rule: `HARNESS.md`.
- `human-judgment=required` work needs explicit user acceptance before merge. Do not rebase or amend an accepted feature tip.

## FRAME

1. Confirm exact checkout identity; route with `AGENT_FAST_PATH.md`.
2. Resolve outcome against `PRODUCT_DECISIONS.md` and `apps/docs/content/`.
3. Fill or update the seeded `.orbit/loop.md` Goal and Scope; raw feedback stays in `.orbit/feedback.jsonl`. Append `primitive=`/`transitions=` per `HARNESS.md` FRAME when needed. When the goal changes a predicate, identity, vocabulary, or schema, list bounded producers, consumers, and dangerous invariants before dispatch; skip that inventory for ordinary local changes.
4. Pull prior feedback: `bin/orbit-feature-feedback relevant --surface=<scope> --json`.
5. Split mixed non-automated venues before dispatch; then run `bin/orbit-feature-acceptance route`.

## BUILD

Start with failing coverage; capture red, make the smallest change, rerun. Load owning skills: `command-designer` + `orbit-cli-development`; Spatie + Pest; `orbit-core-development` / `orbit-sdk-development`; `librarian` + `orbit-docs-development`;
macOS Agent: `tauri-agent-development`.

For each dependency-ready slice, Sol marks building and dispatches one fresh native Codex child using model `gpt-5.6-luna` and `reasoning_effort=low`. The child uses TDD, produces RED/GREEN and focused proof, handles corrections and amends its single checkpoint, and never edits packets or `.orbit`. There are no slice handoffs or proving state. A fresh child handles each next or reopened slice. Sol independently audits the diff, then completes and indexes the checkpoint.

## SLICE PROVE / CHECKPOINT

The Luna child owns slice RED/GREEN and focused proof. Sol records each checkpoint only after independent audit.

## FEATURE PROVE / REVIEW

After all indexed slices complete, Sol owns one diff-routed feature proof, terminal gate, clean candidate, runtime evidence, and feature-level proof receipt.
Sol produces and validates `bin/orbit-feature-proof-receipt` for the exact candidate; Claude and acceptance consume that feature-level receipt.
`composer quality-gate:final-check` is evidence-only and must not rerun Pest, quality-check, or E2E lanes; missing comparable timing means `timing analysis was skipped`.
Run the narrowest relevant verification while building, then the diff-routed broader gate.

When the Goal claims runtime reachability or convergence, proof must directly exercise the claimed final outcome. A failed, excluded, still-required, or deferred final hop means `Verification.runtime` cannot be recorded as `passed`; stay in PROVE. For non-`automated` venues, record the structured runtime receipt on the `Verification.runtime` row per `HARNESS.md` PROVE. A same-candidate proof retry keeps Review and the reviewed tip; a reviewer FIX resets them.

Sol runs the feature proof and terminal gate after slice checkpoints, then records the clean candidate and runtime evidence. Run focused Mago formatting and linting for every changed PHP file, including tests; skip when no PHP changed.
Spawn exactly one independent Claude general reviewer for the review cycle with `bin/orbit-worker-spawn --role=review --cli=claude --brief=<path>`. Missing Claude review tooling is a REVIEW blocker.
The same general reviewer owns blast radius, ESCALATE, and terminal PASS or FIX. FIX identifies the earliest affected complete slice, resets that slice and every later indexed slice to pending/none as dependencies require, then marks the earliest slice ready and building and uses a fresh Luna-low child for each rebuilt slice. Re-prove FEATURE PROVE and review the corrected tip with the same Claude reviewer. On PASS record the exact reviewed HEAD and `human-judgment=required|not-required`.

## ACCEPT

Venue table: `HARNESS.md` Acceptance Venues; `automated` surfaces (docs, tests, repository tooling under `bin/`) still need the diff-routed `composer quality-check`. Run every deterministic acceptance command yourself. Do not hand the user a mechanical command checklist.
CLI retained topology proof runs in a user-attachable `proof-1` window of the feature tmux session; keep it open for the user only when `HUMAN_JUDGMENT: required`.
On `actor=automated`, run
`bin/orbit-feature-acceptance accept --loop=.orbit/loop.md --actor=automated`
to validate and record the candidate in one command. Do not send an acceptance handoff when the actor is automated.
On `actor=user`, arm with `bin/orbit-feature-acceptance ready --loop=.orbit/loop.md`, then record delayed verbatim acceptance with `--actor=user --source-ref=<codex-or-claude-ref>`.

On feedback, use `bin/orbit-feature-feedback record`, invalidate acceptance, return to BUILD, and re-prove. Close feedback through `HARNESS.md` Feedback And Protections; Never solicit a waiver.

## LAND

Prefer `bin/orbit-feature-land --branch=<feature> --worktree=<exact-feature-worktree>`.
Manual LAND follows `HARNESS.md` LAND: lint with `bin/orbit-feature-finalization-check --lint .orbit/loop.md`, validate each destructive mutation on `<exact command>`, execute after `FINALIZATION: PASS`, then compact `bin/orbit-session-archive` from the feature worktree (never cwd main; `--full` only for failure/escalation/security/release scope). Commit archive/index, kill the feature tmux session (`tmux kill-session -t '=feat-<slug>'`, validated by `bin/orbit-feature-finalization-check`), remove the exact clean merged worktree, then delete the exact merged feature branch.
