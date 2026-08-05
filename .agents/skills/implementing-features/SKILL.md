---
name: implementing-features
description: Use when implementing an Orbit feature, bug fix, command behavior change, documentation correction, or project workflow improvement.
---

# Implementing Orbit Features

Own the requested result from frame through landing:

`FRAME -> BUILD <-> PROVE -> ACCEPT -> LAND`

The current feature owner may implement directly. Use workers only when useful:
a bounded independent slice can materially reduce elapsed time or improve a
concrete decision. The owner remains accountable for integration and the final
result.

## Non-Negotiable Boundaries

- Work in an isolated worktree created by `bin/orbit-prepare-worktree`. It seeds
  `.orbit/loop.md` when it is missing. If preparation or the seed is missing,
  report the setup blocker; do not recreate the setup flow manually.
- Preserve unrelated user state.
- Never run, delegate, background, schedule, hook, script, or trigger a
  `composer test:e2e*` command. Only explain that manual lane after the user
  explicitly asks; agents may inspect an artifact the user independently
  creates.
- Keep product docs, executable coverage, and implementation aligned.
- Reject every nonignored untracked file at review, acceptance, finalization,
  and retained-source sync boundaries.
- Work with `human-judgment=required` needs explicit user acceptance before merge.
- Do not rebase or amend an accepted feature tip.

## FRAME

1. Read `AGENTS.md`, `AGENT_FAST_PATH.md`, and `HARNESS.md`.
2. Confirm exact checkout identity and current state.
3. Resolve the outcome, owned paths, constraints, and out-of-scope work. Check
   `PRODUCT_DECISIONS.md` and the relevant `apps/docs/content/` authority.
4. Fill or update the seeded `.orbit/loop.md` Goal and Scope. Keep the anchor
   compact; raw feedback belongs in `.orbit/feedback.jsonl`.
5. Retrieve relevant prior feedback by exact or parent surface when a stable
   scope exists. The command searches the primary archive corpus by default and
   returns matched records plus linked promotions and waivers:

   ```bash
   bin/orbit-feature-feedback relevant --surface=<scope> --json
   ```

6. Decide whether any bounded worker is genuinely useful. There is no
   mandatory dispatch or extra dispatch paperwork.

## BUILD

For behavior changes, start with failing executable coverage in the owning
framework. Pest is required for PHP/Laravel tests; use the native framework for
Rust, JavaScript, shell, and other stacks. Capture the literal red result, make
the smallest correct change, then rerun the focused check.

Follow the relevant domain skills. In particular:

- command behavior: `command-designer` and `orbit-cli-development`;
- Laravel/PHP: Spatie Laravel/PHP guidance and Pest for tests;
- shared contracts: `orbit-core-development` or `orbit-sdk-development`;
- docs/Librarian: `librarian` and `orbit-docs-development`;
- macOS Agent: `tauri-agent-development`.

Workers receive an exact worktree, owned paths, done condition, and verification
command. Stop or redirect a worker that leaves scope. Do not create standing
observer, analyzer, capture, or specialist lanes.

## PROVE

Run the narrowest relevant verification while building. Before review, run the
diff-routed broader gate:

- docs-only: `composer docs-lint`;
- any non-docs repository change: `composer quality-check`;
- rendering/progress/stream/TTY/cadence/repaint/liveness risk: PTY capture;
- integrated runtime behavior: the real proof venue after review.

When the Goal claims runtime reachability or convergence, proof must directly
exercise the claimed final outcome. Configuration validation, artifact
presence, and successful intermediate hops are supporting evidence, not
substitutes. A failed, excluded, still-required, or deferred final hop means
`Verification.runtime` cannot be recorded as `passed`. For non-`automated`
venues, record the structured runtime receipt on the existing
`Verification.runtime` row (candidate-bound `candidate=`, `venue=`,
`environment=`, `target=` or `command=`, `expected=`, `observed=`,
`result=passed`, exact `evidence=` path). Do not invent a parallel receipt,
artifact, or lane. If the final hop is incomplete or deferred, remain in
PROVE and re-prove it before ACCEPT or LAND.

Promoted deterministic feedback protections are part of these normal gates
when their surface matches. Do not invent a parallel pass-receipt system.

After focused checks pass, commit the candidate and confirm the worktree is
clean before the diff-routed broader gate, general review, and acceptance. Gate
artifacts and decisions must bind the exact committed HEAD. After a reviewer
`FIX`, commit the clean correction delta and repeat the affected proof before
re-review.

Run `composer quality-gate:final-check` only as an evidence read. It must not
rerun Pest or quality-check. Treat missing comparable timing as
`timing analysis was skipped`, not as failure.

### One Independent Review

Use one fresh reviewer with `.agents/review-personas/general.md`. Give it exact
checkout proof, base/diff, goal, relevant product authority, tests, and evidence.
It returns:

- `CHECKOUT_PROOF: ...`
- concrete findings, if any;
- `BLAST_RADIUS: not-required|complete|gaps`
- `HUMAN_JUDGMENT: required|not-required`
- `VERDICT: PASS|FIX|ESCALATE`

Blast radius stays inside the same general reviewer. Use `not-required -
<reason>` for a local change. A product decision, ownership boundary, transport,
shared vocabulary, or shared schema requires `complete -
evidence=<repository-wide search, inventory, or lintable check>;
result=<summary>`. `gaps` cannot PASS or enter ACCEPT.

On FIX, record `Review: fix`, reset `Reviewed feature tip: none` and `Blast
radius: pending`, return to BUILD
and add coverage before the fix where practical, then commit and re-review the
proven delta. On ESCALATE, dispatch only the named specialist with the concrete
high-risk question. The specialist answers back to the same general reviewer,
which issues the terminal PASS or FIX even when no code delta was needed. One
reviewer PASS is enough when no specialist question exists.

Record Review in `.orbit/loop.md` with
`human-judgment=required|not-required`. On terminal PASS, record
`Reviewed feature tip` as the exact reviewed HEAD and record the reviewer blast
radius classification plus closure evidence; acceptance rejects any other
commit, a PASS without that decision, or unresolved blast-radius gaps.

## ACCEPT

After completing the diff-derived venue proof, arm acceptance:

```bash
bin/orbit-feature-acceptance ready --loop=.orbit/loop.md
```

The conservative venues are:

- `automated` as the proof venue for docs, tests, declarative workflow files,
  and repository tooling under `bin/`; repository tooling still requires
  diff-routed `composer quality-check`;
- `retained-incus` for CLI and node/runtime behavior;
- `browser` for web UI;
- `host-macos` for native macOS behavior.

Run every deterministic acceptance command yourself and inspect the result.
Do not hand the user a mechanical command checklist. Human acceptance is only
for a prepared experience that still needs judgment about intent, UX, or
real-world behavior. A changed executable or conservatively derived venue is
not sufficient reason to involve the user; when no human-judgment surface
remains, require the reviewer to record `HUMAN_JUDGMENT: not-required` and use the
automated actor after completing the diff-derived proof venue. Never downgrade
the venue merely because the actor is automated.

For retained Incus, use the smallest source-mounted topology, sync only required
roles, and use one agent-owned Solo terminal at `/home/orbit/orbit-run`. Keep it
open for the user only when `HUMAN_JUDGMENT: required`. For browser, open the
exact candidate URL. For macOS, open or run the exact candidate on the
implementing Mac.

`ready` prints the required venue and actor. When it prints `actor=automated`,
immediately run:

```bash
bin/orbit-feature-acceptance accept \
  --loop=.orbit/loop.md \
  --actor=automated
```

Do not send an acceptance handoff when the actor is automated. When it prints
`actor=user`, give the user one concise handoff for the remaining judgment with
the experience already prepared, then record the verbatim acceptance:

```bash
bin/orbit-feature-acceptance accept \
  --loop=.orbit/loop.md \
  --actor=user \
  --source-ref=<codex-or-solo-ref>
```

On feedback:

1. append the raw message with `bin/orbit-feature-feedback record`; redaction
   happens before persistence;
2. invalidate acceptance;
3. fix in BUILD;
4. resync/restart only what changed;
5. repeat affected proof and review;
6. return the same acceptance surface.

Automated acceptance is allowed only after independent
`HUMAN_JUDGMENT: not-required` review and the diff-derived proof venue.

If main advances, merge current main into the feature branch, return through
PROVE, and repeat ACCEPT against the new feature tip. Do not refresh a recorded
main tip as free-form re-proof. Any feature HEAD change invalidates the earlier
acceptance.

## Feedback Promotion

Non-secret feedback events are immutable. Close generalized feedback with the
strongest practical protection, in order:

Implementation feedback is actionable by default; acceptance evidence is not.
Do not enter ACCEPT or LAND while an actionable record lacks a linked promotion
or user-sourced waiver. A failed protection reopens the record.

1. test, linter, schema, state check;
2. tool, default, generated contract, template;
3. cheap calibrated UX check;
4. targeted general-review context;
5. concise instruction;
6. extra ceremony only when unavoidable.

Dogfood the concrete rejected and accepted pair first. Every promotion records
both examples. Prefer deterministic protection. Do not create a semantic grader
without a named promoted expectation and both examples; `kind=model` is a
supported future value, not a default subsystem. If a future grader exists,
`UNKNOWN` never passes. Never solicit a waiver; only record one the user
volunteers, and require its safe Codex/Solo source reference plus verbatim user
message rather than accepting a bare `source=user` claim.

## LAND

1. Fill final Proof and Status in `.orbit/loop.md`.
2. Lint it with `bin/orbit-feature-finalization-check --lint .orbit/loop.md`.
3. Confirm exact successful gates, reviewer PASS, acceptance, feature HEAD,
   actual `git rev-parse main`, clean identity, and conflict-free merge preview.
4. Validate the exact merge mutation with
   `bin/orbit-feature-finalization-check <exact git command>`.
   After `FINALIZATION: PASS`, execute that exact command separately.
5. After merge, keep the accepted feature worktree open and run its now-landed
   `bin/orbit-session-archive` with the feature worktree as cwd, never cwd main.
   Cite each retained evidence or quality-gate file in `.orbit/loop.md` as one
   exact inline-code path; do not cite directories, prose, or padded spans.
   Use `--full` only for failure, escalation, security/release scope, or explicit
   request.
6. Commit the archive/index.
7. After the archive/index commit:
   - Validate each cleanup mutation with
     `bin/orbit-feature-finalization-check <exact git command>`.
     After `FINALIZATION: PASS`, execute that exact cleanup command separately.
   Leave the primary checkout on updated `main` without altering unrelated
   files.

## Trigger-Only Process Improvement

Clean loops do no retrospective work. Route only a failed promoted protection,
severe preventable safety incident, reviewer-confirmed recurring process
failure, or explicit user process feedback to the human-invoked loop review.
There may be one active loop experiment. It uses existing compact receipts, one
target metric, a fixed window, and revert by default. Do not create generic
evaluator tooling for a one-off experiment.

## Completion Report

Report the outcome, acceptance surface, exact verification, reviewer verdict,
accepted feature/main tips, archive, and any unresolved blocker. Do not make the
user read implementation narration to discover whether the feature is ready.
