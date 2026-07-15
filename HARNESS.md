# Orbit Development Harness

This is the repository-development route after `AGENTS.md` and
`AGENT_FAST_PATH.md`. Product behavior remains authoritative under
`apps/docs/content/`; this file owns how a feature moves safely through the
repository.

## The Feature Loop

Every feature uses one state machine:

`FRAME -> BUILD <-> PROVE -> ACCEPT -> LAND`

The current feature owner owns FRAME through LAND and may implement directly.
Workers are optional and bounded: use them only when an independent slice can
finish materially faster or improve a concrete decision. No extra dispatch
paperwork is required.

The local anchor is the compact `.orbit/loop.md` seeded by
`bin/orbit-prepare-worktree`. It records only Goal, Scope, Proof, Status, and a
pointer to `.orbit/feedback.jsonl`. Raw feedback, transcripts, and retrospective
taxonomies do not belong in the anchor.

### FRAME

1. Resolve the verifiable outcome, owned paths, constraints, and exclusions.
2. Reconcile the request with `PRODUCT_DECISIONS.md` and the relevant product
   docs. Stop only for unresolved product intent or missing external authority.
3. Create the isolated worktree with `bin/orbit-prepare-worktree`; it seeds
   `.orbit/loop.md` when missing. Fill Goal, Scope, branch, worktree, and
   scratchpad/source reference before editing.
4. Select prior feedback with `bin/orbit-feature-feedback relevant` when the
   changed surface has a stable scope. It searches the primary session archive
   corpus by default and returns matched records with their linked promotions
   and waivers.

### BUILD

Keep docs, executable coverage, and implementation aligned. Start with failing
coverage in the owning framework; Pest is the PHP/Laravel framework, not a rule
for Rust, JavaScript, shell, or other stacks. Prefer a small working vertical
slice and existing project abstractions.

If a bounded worker is useful, give it an exact checkout, owned paths, done
condition, and verification. The feature owner remains responsible for the
integrated result and stops or redirects workers that leave scope.

### PROVE

Run the smallest relevant checks first, then the diff-routed broader gate:

- docs-only: focused docs checks and `composer docs-lint`;
- non-docs repository changes: focused owning tests and `composer quality-check`;
- integrated runtime behavior: the real proof venue below;
- rendering, progress, streaming, TTY, cadence, repaint, or liveness risk:
  capture PTY evidence; ordinary commands do not pay a PTY tax.

After focused checks pass, commit the candidate and confirm a clean worktree
before the diff-routed broader gate, general review, and acceptance. Those
artifacts and decisions bind the exact committed HEAD, not a dirty approximation.

`composer quality-gate:final-check` is evidence-only. It must not rerun Pest,
quality-check, or E2E lanes. Timing analysis may be skipped when no comparable
baseline exists.

After checks pass, use one independent general reviewer from
`.agents/review-personas/general.md`. The reviewer returns `PASS`, `FIX`, or
`ESCALATE`, `BLAST_RADIUS: not-required|complete|gaps`, and
`HUMAN_JUDGMENT: required|not-required`.

Blast radius is the prevention hook inside the same general reviewer, not a new
lane. Use `not-required - <reason>` for a local change. A product decision,
ownership boundary, transport, shared vocabulary, or shared schema requires
`complete - evidence=<repository-wide search, inventory, or lintable check>;
result=<summary>`. `gaps` cannot PASS or enter acceptance.

- `PASS`: continue.
- `FIX`: record `Review: fix`, reset `Reviewed feature tip: none` and `Blast
  radius: pending`, return to
  BUILD, add or adjust executable coverage, fix, commit the clean delta, repeat
  affected proof, and re-review the delta.
- `ESCALATE`: name one specialist and one concrete high-risk question. A
  specialist answers that question only back to the same general reviewer.
  That reviewer then issues the terminal `PASS` or `FIX`, even when the answer
  requires no code delta. There are no standing specialist lanes.

On terminal PASS, record `Reviewed feature tip` as the exact reviewed HEAD and
include `human-judgment=required|not-required` in Review. Record the reviewer's
Blast radius classification and closure evidence on the loop row. Acceptance
refuses a PASS recorded against any other commit, without that decision, or
with unresolved blast-radius gaps.

## Acceptance Venues

`bin/orbit-feature-acceptance ready` conservatively derives one venue from the
changed files:

| Venue | Use |
| --- | --- |
| `automated` | Proof venue for docs, tests, declarative workflow files, and repository tooling under `bin/` |
| `retained-incus` | CLI commands and server or node runtime behavior |
| `browser` | Gateway or docs web UI |
| `host-macos` | Native macOS Agent behavior |

Run `ready` only after recording the venue proof. It refuses every
non-`automated` venue unless `Verification.runtime` is `passed`.
Repository tooling still requires diff-routed `composer quality-check`; it has
no retained topology target.

Work that still requires human judgment needs explicit user acceptance before
merge. Other work is accepted by the automated actor after reviewer PASS and
the same diff-derived proof venue.
The venue selects the required proof surface; the actor selects whether a user
judgment remains. A `human-judgment=not-required` review permits an automated
actor only after the diff-derived venue is proven. It never downgrades
`retained-incus`, `browser`, or `host-macos` proof to `automated`.

Agents run every deterministic check and inspect its output before acceptance.
Never ask the user to execute a check the agent can execute. The user receives
only a prepared surface that requires human judgment about intent, UX, or
real-world behavior. Executable files or a conservatively derived venue do not
by themselves create a human acceptance task. If no judgment surface remains,
the general reviewer records `HUMAN_JUDGMENT: not-required` and acceptance is
recorded by the automated actor at the already proven venue.

### Retained Incus Acceptance

Use the existing source-mounted Incus topology commands, never an artifact
build as the default acceptance path:

1. Run `bin/orbit-secret-scan` and reject every nonignored untracked file.
2. Start or reuse the smallest role set from the implementation worktree.
3. Sync only the required checkout roles to `/home/orbit/orbit-run`.
4. Open one ready Solo terminal inside the relevant VM at
   `/home/orbit/orbit-run` and verify launcher identity.
5. Exercise changed human output, JSON, failures, side effects, idempotency,
   performance, and PTY behavior yourself where applicable.
6. Only when judgment still remains, send one concise `ACCEPTANCE READY`
   handoff pointing at the already prepared experience and the decision the
   user must make. Do not hand off a command or check that an agent can run.

CLI retained topology proof must run in a Solo terminal. Keep that terminal open
for a user only when `HUMAN_JUDGMENT: required`; otherwise it is agent-owned
proof and may close after completion. On feedback, keep the topology, invalidate
acceptance, fix, resync, restart only affected services, and repeat the affected
proof.

The `composer test:e2e*` commands are human-only. Agents never run, delegate,
background, schedule, hook, script, or trigger them. Existing manual E2E
artifacts may be read and triaged; their existence never authorizes execution.

### Browser And macOS Acceptance

For `browser`, open the exact candidate URL in the in-app browser before the
handoff and give only the actions that matter. For `host-macos`, open or run the
exact candidate app/command on the implementing Mac. Incus is not a substitute
for native macOS proof.

### Acceptance Identity

Record acceptance with `bin/orbit-feature-acceptance`:

- user acceptance reads the verbatim message from STDIN and requires its
  `codex://` or `solo://` source reference;
- automated acceptance always requires `human-judgment=not-required`;
- `Reviewed feature tip` is the exact HEAD that received reviewer PASS;
- `Accepted feature tip` is the exact feature `HEAD`;
- `Accepted main tip` is the actual `git rev-parse main` at acceptance or
  re-proof time, never the merge base.

If the feature tip moves, acceptance is invalid. If main moves, merge current
main into the feature branch, return through PROVE, and repeat ACCEPT against
the new feature tip. A conflict returns to BUILD. Updating the recorded main
tip without integrating main and repeating those states is never re-proof.

## Feedback And Protections

All non-secret user feedback is stored verbatim as immutable events in
`.orbit/feedback.jsonl`. Secret-shaped values are redacted in memory before the
event is appended; only the redacted context, original SHA-256, and rule ids are
durable. The complete secret-bearing input is never written to a private side
store.

Feedback closes by a product protection or a user-volunteered waiver. A waiver
requires a safe Codex/Solo source reference and the verbatim user message; a
bare `source=user` claim is not evidence. Never ask the user for a waiver merely
to avoid doing the work. Promote reusable feedback in this order:

Implementation feedback is actionable by default. Acceptance evidence is
explicitly non-actionable. ACCEPT, LAND, and archive construction block while
any actionable record lacks a linked promotion or user-sourced waiver; a later
`protection.failed` event reopens that feedback.

1. test, linter, schema, or state check;
2. tool, safe default, generated contract, or template;
3. cheap calibrated UX check;
4. targeted context for the general reviewer;
5. concise instruction;
6. extra ceremony only when unavoidable.

Every promoted protection names one rejected example and one accepted example.
Applicable deterministic protections run through the normal diff-routed proof;
there is no second receipt gate. Semantic similarity is never a hard gate. Do
not create a semantic grader without one named promoted expectation and both
examples; if one is eventually justified, `UNKNOWN` never passes.

The existing monotonic quality-check progress protection is the reference
example: the rejected `Running -> Queued` frame fails
`bin/quality-check-progress-frame-check`, while the accepted monotonic frame
passes.

## LAND

1. Run `bin/orbit-feature-finalization-check --lint .orbit/loop.md`.
2. Confirm the worktree has no tracked dirt or nonignored untracked files.
3. Confirm exact successful diff-routed artifacts, reviewer PASS, accepted
   feature/main tips, and a conflict-free non-mutating merge preview.
4. Validate the exact merge mutation with
   `bin/orbit-feature-finalization-check <exact git command>`.
   After `FINALIZATION: PASS`, execute that exact command separately.
5. After merge, keep the accepted feature worktree open and run its now-landed
   `bin/orbit-session-archive` with the feature worktree as cwd. Do not run the
   compact archive from main. Archives are compact by default: `loop.md`,
   optional `feedback.jsonl`, regular files cited by the loop as one exact
   inline-code path below `.orbit/evidence/` or `.orbit/quality-gates/`, and a
   versioned receipt bound to the landed feature branch and every archived
   byte. Cite files, never proof directories; missing, malformed, or unsafe
   citations block archival.
6. Use `bin/orbit-session-archive --full` only for failure diagnosis,
   escalation, security or release scope, or an explicit request.
7. Update the session index and commit the archive/index.
8. After the archive/index commit:
   - Validate each cleanup mutation with
     `bin/orbit-feature-finalization-check <exact git command>`.
     After `FINALIZATION: PASS`, execute that exact cleanup command separately.
   Leave the primary checkout on updated `main` without disturbing unrelated
   files.

Compact cleanup proof is a regular `loop.md` plus a valid schema-v2 compact
receipt. Historical/full archives remain valid through their legacy manifests.

## Trigger-Only Loop Improvement

Clean loops create no experiment, retrospective, analyzer lane, capture lane,
metrics table, or signal record. Process improvement starts only after one of:

- a failed promoted protection;
- a severe preventable safety incident;
- a reviewer-confirmed recurring process failure; or
- explicit user process feedback.

There may be one active loop experiment at a time. Keep it in a Solo scratchpad
tagged `loop-experiment` with the trigger, cause hypothesis, smallest change,
one target metric, exact derivation from existing compact receipts, baseline
boundary, fixed window, and revert command. Revert by default when the target
does not improve, a hard protection fails, or ordinary delivery slows
materially. Do not create generic evaluator tooling for a one-off calculation.

The prevention metric counts escaped same-surface defects after terminal PASS,
not internal commit count or autonomous pre-land rework. The latter is recovery
that remained inside the loop.

Hard security, correctness, acceptance, and evidence-integrity protections are
never experiments. Historical session and signal tools remain available for an
explicit human-requested diagnostic; they are not ordinary delivery gates.

## Stop Conditions

Stop and request direction only when product intent is genuinely ambiguous,
external authority is missing, safe required infrastructure is unavailable, or
the user must make a judgment automation cannot make. A test failure, reviewer
FIX, main movement, or acceptance feedback is a state transition, not a reason
to abandon the loop.
