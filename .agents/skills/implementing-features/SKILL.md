---
name: implementing-features
description: Implement an Orbit feature, bug fix, command change, or docs correction.
---

# Implementing Orbit Features

Own the requested result through `FRAME -> BUILD <-> PROVE -> ACCEPT -> LAND`.
`HARNESS.md` is the canonical loop contract.
The orchestrating session (Codex or Claude) that the human started is the sole feature owner.
Workers run in the feature tmux session `feat-<slug>` created by `bin/orbit-prepare-worktree`.

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

Dispatch substantive repository edits to Grok workers with `bin/orbit-worker-spawn --role=impl --cli=grok --brief=<path>`. Do not substitute an owner subagent or direct owner implementation.
Wait for workers with `bin/orbit-worker-watch`; read handoff files. Periodically study `bin/orbit-worker-capture <id>`. Observation is not intervention: elapsed time, no diff, or context collection is not a stall. Intervene on stale output, an exited pane, blocked/request status, a repeated failed action, visible loop or drift, or a concrete question.
Every brief requires `bin/orbit-worker-heartbeat <id> --status=<working|blocked> --note=<text>` at working or blocked updates, and `bin/orbit-worker-handoff <id> <file> [--note=<text>]` as the atomic terminal operation; workers never merge.
Status questions and partial blockers are nonterminal. Stop only at LAND, required human judgment, or a whole-goal blocker.
Impl handoff names `candidate=<40-character sha>` and a valid SHA-bound `bin/orbit-feature-proof-receipt`.
Re-arm `bin/orbit-worker-watch` after handling an event with `--ack=<snapshot>` or `--target=<id>`. `--ignore` remains as cheap compatibility.
Stop finished workers with `bin/orbit-worker-stop <id>` (or `--all-finished`) before LAND; never kill windows or servers with raw tmux commands.
Missing tmux, grok, or claude on the machine is a blocker.

## PROVE

Run the narrowest relevant verification while building, then the diff-routed broader gate: docs-only `composer docs-lint`; non-docs `composer quality-check`. `composer quality-gate:final-check` is an evidence read; it must not rerun Pest or quality-check; missing comparable timing means `timing analysis was skipped`.

When the Goal claims runtime reachability or convergence, proof must directly exercise the claimed final outcome. A failed, excluded, still-required, or deferred final hop means `Verification.runtime` cannot be recorded as `passed`; stay in PROVE. For non-`automated` venues, record the structured runtime receipt on the `Verification.runtime` row per `HARNESS.md` PROVE. A same-candidate proof retry keeps Review and the reviewed tip; a reviewer FIX resets them.

After focused checks pass, commit the candidate and confirm the worktree is clean. Run focused Mago formatting and linting for every changed PHP file, including tests, before each candidate commit; skip when no PHP changed. The implementer owns focused checks and the one terminal gate; owner
and reviewer consume the exact-SHA receipt without rerunning it.
Spawn one independent Claude general reviewer for the review cycle with `bin/orbit-worker-spawn --role=review --cli=claude --brief=<path>`.
The same general reviewer owns blast radius, ESCALATE, and terminal PASS or FIX. FIX resets Review, reviewed tip, and Blast radius; return to Grok BUILD, prove, commit, then reuse the reviewer for the corrected tip. On PASS record the exact reviewed HEAD and `human-judgment=required|not-required`.

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
