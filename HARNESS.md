# Orbit Development Harness

Product behavior remains authoritative under `apps/docs/content/`; this file
owns how a feature moves safely through the repository.

## The Feature Loop

Every feature uses one state machine:

`FRAME -> BUILD <-> PROVE -> ACCEPT -> LAND`

The orchestrating session (Codex or Claude) that the human started is the sole feature owner.
Workers run in the feature tmux session `feat-<slug>` created by `bin/orbit-prepare-worktree`.

The local anchor is the compact `.orbit/loop.md` seeded by
`bin/orbit-prepare-worktree`. It records only Goal, Scope, Proof, Status, and a
pointer to `.orbit/feedback.jsonl`. Raw feedback, transcripts, and retrospective
taxonomies do not belong in the anchor.

Owner prepares the worktree, fills `.orbit/loop.md`, and writes briefs under
`.orbit/workers/briefs/`.

### FRAME

1. Resolve the verifiable outcome, owned paths, constraints, and exclusions.
2. Reconcile the request with `PRODUCT_DECISIONS.md` and the relevant product
   docs; stop only for unresolved intent or missing external authority.
3. Create the isolated worktree with `bin/orbit-prepare-worktree`; it seeds
   `.orbit/loop.md` when missing. Fill Goal, Scope, branch, worktree, and
   Session before editing. For stateful, lifecycle, or concrete UX features,
   append one optional compact clause on the existing Scope `Owned` row:
   `primitive=<exact requested primitive>; transitions=success:<terminal success>|failure:<terminal failure>|retry:<retry>|stop-restart:<stop or restart>|stale:<stale-state or n/a>`.
   Omit the clause for ordinary/local changes. When the Goal changes a
   predicate, identity, vocabulary, or schema, FRAME must list the bounded
   producers, consumers, and dangerous invariants before dispatch. Omit that
   inventory for ordinary local changes. Deterministic lint checks only
   marker syntax, not statefulness or prose; `bin/orbit-loop-contract.php`
   teaching errors are authoritative. Do not add a new Scope row, lane, or
   semantic grader for this framing.
4. Select prior feedback with `bin/orbit-feature-feedback relevant` when the
   changed surface has a stable scope; it searches the primary session archive
   corpus with linked promotions and waivers.

### BUILD

Keep docs, tests, and implementation aligned. Start with failing coverage in
the owning framework. Prefer a small vertical slice and existing abstractions.

Dispatch substantive repository edits to Grok workers with `bin/orbit-worker-spawn --role=impl --cli=grok --brief=<path>` (`grok --yolo --reasoning-effort medium` in the worktree). Do not substitute an owner subagent or direct owner implementation.
Wait for workers with `bin/orbit-worker-watch`; read handoff files. Periodically study `bin/orbit-worker-capture <id>`. Observation is not intervention: elapsed time, no diff, or context collection is not a stall. Intervene on stale output, an exited pane, blocked/request status, a repeated failed action, visible loop or drift, or a concrete question.
Every brief requires `bin/orbit-worker-heartbeat <id> --status=<working|blocked> --note=<text>` at working or blocked updates, and `bin/orbit-worker-handoff <id> <file> [--note=<text>]` as the atomic terminal operation; workers never merge.
Re-arm `bin/orbit-worker-watch` after handling an event with `--ack=<snapshot>` or `--target=<id>`. `--ignore` remains as cheap compatibility.
Stop finished workers with `bin/orbit-worker-stop <id>` (or `--all-finished`) before LAND; never kill windows or servers with raw tmux commands.
Impl handoff names `candidate=<40-character sha>` and a valid SHA-bound `bin/orbit-feature-proof-receipt`.
Every `tmux kill*` form from a repository shell is blocked except the validated LAND `kill-session -t '=feat-<slug>'` boundary.
Missing tmux, grok, or claude on the machine is a blocker.

### PROVE

Run the smallest relevant checks first, then the diff-routed broader gate:
docs-only focused docs checks and `composer docs-lint`; non-docs focused owning
tests and `composer quality-check`; integrated runtime at the real proof venue;
PTY evidence only for TTY/stream/liveness risk.

When the Goal claims runtime reachability or convergence, proof must directly
exercise the claimed final outcome. Configuration validation, artifact
presence, and successful intermediate hops are supporting evidence, not
substitutes. A failed, excluded, still-required, or deferred final hop means
`Verification.runtime` cannot be recorded as `passed`. Acceptance and
finalization share that contract for every non-`automated` venue: the existing
`Verification.runtime` row must carry a candidate-bound structured receipt
(`candidate=`, `venue=`, `environment=`, `target=` or `command=`, `expected=`,
`observed=`, `result=passed`, and one exact `evidence=` path under
`.orbit/evidence/` or `.orbit/quality-gates/`). `bin/orbit-feature-acceptance`
and `bin/orbit-feature-finalization-check` validate receipt fields, evidence
paths, and deferred-language wording deterministically; their teaching errors
are the authority on receipt mechanics. Free-form wording cannot turn a failed
or post-LAND/post-merge deferred hop into a pass. Unresolved terminal runtime
proof stays in PROVE: `ready`/`accept` refuse, normalize `State` to `prove`
with `Acceptance: pending` and accepted tips `none`, preserve a still-valid
Review and Reviewed feature tip, and return through BUILD -> PROVE before
ACCEPT. A same-candidate proof retry is not a reviewer FIX: the retry keeps
Review and the reviewed tip; only a reviewer FIX resets them. A repair that
moves HEAD still needs a refreshed review via the existing identity check. Do
not invent a post-LAND closure proof. Historical archives stay readable; the
strict receipt applies to new acceptance or finalization.

After focused checks pass, commit the candidate and confirm a clean worktree
before the diff-routed broader gate, general review, and acceptance. When
production PHP files changed, run focused Mago on those files before the first
implementation handoff; skip focused Mago when none changed. The implementer owns focused checks and the one terminal gate; owner
and reviewer consume the exact-SHA receipt without rerunning it.

`composer quality-gate:final-check` is evidence-only. It must not rerun Pest,
quality-check, or E2E lanes; timing analysis may be skipped when no comparable
baseline exists.

Spawn one independent Claude general reviewer for the review cycle with `bin/orbit-worker-spawn --role=review --cli=claude --brief=<path>` (`claude --dangerously-skip-permissions --model opus --effort high` in the worktree).
Use `.agents/review-personas/general.md`. Require checkout proof.

Blast radius is the prevention hook inside the same general reviewer, not a new
lane. Use `not-required - <reason>` for a local change. A product decision,
ownership boundary, transport, shared vocabulary, or shared schema requires
`complete - evidence=<repository-wide search, inventory, or lintable check>;
result=<summary>`. `gaps` cannot PASS or enter acceptance.

- `PASS`: continue.
- `FIX`: record `Review: fix`, reset `Reviewed feature tip: none` and `Blast
  radius: pending`, return to Grok BUILD, fix and commit the clean delta, repeat
  affected proof, and reuse the same reviewer for the new tip.
- `ESCALATE`: name one specialist and one concrete high-risk question; the
  specialist answers only back to the same general reviewer, which then issues
  the terminal `PASS` or `FIX` even without a code delta. There are no
  standing specialist lanes.

On terminal PASS, record `Reviewed feature tip` as the exact reviewed HEAD,
`human-judgment=required|not-required`, and the reviewer's blast-radius
classification with closure evidence. Acceptance refuses a PASS against any
other commit, without that decision, or with unresolved blast-radius gaps.

## Acceptance Venues

After FRAME, run the read-only diff-derived route before expensive PROVE work
(no loop packet or cleanliness/review gates):

```bash
bin/orbit-feature-acceptance route
```

It prints the candidate and base identities, changed files, and venue.
`route`/`ready`/`accept` share this derivation; base defaults to `main` and
errors fail closed.

`bin/orbit-feature-acceptance ready` uses that same venue from the changed
files:

| Venue | Use |
| --- | --- |
| `automated` | Docs, tests, declarative workflow files, repository tooling under `bin/`, and repository-only TypeScript SDK packaging under `packages/sdk-typescript/**` |
| `retained-incus` | Shared core (`packages/core/src/**`), PHP SDK (`packages/sdk/**`; production require of CLI/gateway), CLI commands, and server or node runtime behavior |
| `browser` | Gateway or docs web UI |
| `host-macos` | Native macOS Agent behavior |

Run `ready` only after recording the venue proof. It refuses every
non-`automated` venue unless `Verification.runtime` is `passed`.
Repository tooling and TypeScript SDK automation still require diff-routed
`composer quality-check`; they have no retained topology target. Receipts that
claim a live/production surface must use exact `environment=live`; ordinary
retained topology proof may keep `environment=dev-fixture`.

Work that needs human judgment needs explicit user acceptance before merge.
The venue selects proof; the actor selects judgment. A
`human-judgment=not-required` review permits automated acceptance only after
reviewer PASS and venue proof; it never changes the venue. `retained-incus`,
`browser`, and `host-macos` are orthogonal, not a strength ladder. At most one
per candidate; mixed diffs fail closed and must be split. Automation-only
paths may coexist.

Agents run and inspect every deterministic check. Never ask the user to execute
a check the agent can execute. Give the user only a prepared surface that
requires human judgment about intent, UX, or real-world behavior. Otherwise
record `HUMAN_JUDGMENT: not-required` and accept at the proven venue.

### Retained Incus Acceptance

Use the existing source-mounted Incus topology commands, never an artifact
build as the default acceptance path:

1. Run `bin/orbit-secret-scan` and reject every nonignored untracked file.
2. Start or reuse the smallest role set from the implementation worktree.
3. Sync only the required checkout roles to `/home/orbit/orbit-run`.
4. Open one user-attachable `proof-1` window of the feature tmux session at
   `/home/orbit/orbit-run` and verify launcher identity.
5. Exercise changed human output, JSON, failures, side effects, idempotency,
   performance, and PTY behavior yourself where applicable.
6. Only when judgment still remains, send one concise `ACCEPTANCE READY`
   handoff naming the prepared experience and the decision the user must
   make. Do not hand off a command or check an agent can run.

CLI retained topology proof runs in a user-attachable `proof-1` window of the feature tmux session; keep it open for the user only when `HUMAN_JUDGMENT: required`.

Otherwise it is agent-owned proof and may close after completion. On feedback,
keep the topology, invalidate acceptance, fix, resync only what changed, and
repeat the affected proof.

The `composer test:e2e*` commands are human-only: they run only when the
user explicitly invokes the Composer command from a shell, and skills, hooks,
release flows, and default scripts must not trigger them. Agents never run,
delegate, background, schedule, hook, script, or trigger them. Never ask the
user to run them for ordinary feature completion; use retained topology proof.
Existing manual E2E artifacts may be read and triaged; their existence never
authorizes execution. This is the canonical E2E rule; other agent docs carry
one-sentence pointers.

### Browser And macOS Acceptance

For `browser`, open the exact candidate URL before the handoff and give only
the actions that matter. For `host-macos`, open or run the exact candidate on
the implementing Mac; Incus is not a substitute for native macOS proof.

### Acceptance Identity

Record acceptance with `bin/orbit-feature-acceptance`:

- automated acceptance validates and records the exact candidate in one
  `accept --actor=automated` command; delayed human acceptance still arms with
  `ready` and later records with `accept`;
- user acceptance reads the verbatim message from STDIN and requires its `codex://` or `claude://` source reference;
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
durable. Secret-bearing input is never written aside.

Feedback closes by a product protection or a user-volunteered waiver. A waiver
requires a safe Codex or Claude source reference and the verbatim user message; a
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

Every promoted protection names one rejected example and one accepted example
and runs through the normal diff-routed proof; there is no second receipt
gate. Semantic similarity is never a hard gate. Do not create a semantic
grader without one named promoted expectation and both examples; if one is
eventually justified, `UNKNOWN` never passes.

The monotonic quality-check progress protection (rejected `Running -> Queued`
frame vs accepted monotonic frame, documented with the command UX contracts) is
the reference example.

## LAND

Prefer the resumable coordinator on primary `main`:

```bash
bin/orbit-feature-land \
  --branch=<feature> \
  --worktree=<exact-feature-worktree>
```

Use `--status`/`--plan` for a read-only next phase, and `--one-step` to execute
only the next incomplete boundary. Resume is idempotent from Git, the committed session archive/index, and the tmux session state.

LAND gets minimum venue from the exact accepted candidate contract in an
isolated subprocess; all other finalization checks stay main-owned.

Manual LAND remains validate-then-execute for each destructive mutation:

1. Run `bin/orbit-feature-finalization-check --lint .orbit/loop.md`.
2. Confirm the worktree has no tracked dirt or nonignored untracked files.
3. Confirm exact successful diff-routed artifacts, reviewer PASS, accepted
   feature/main tips, and a conflict-free non-mutating merge preview.
4. Validate the exact merge mutation with
   `bin/orbit-feature-finalization-check <exact git command>`.
   After `FINALIZATION: PASS`, execute that exact command separately.
5. After merge, keep the accepted feature worktree open and run its now-landed
   `bin/orbit-session-archive` with the feature worktree as cwd, never from
   main. Archives are compact by default: `loop.md`, optional
   `feedback.jsonl`, loop-cited proof files as exact inline-code paths under
   the evidence, quality-gates, or release-evidence trees, structured worker
   handoffs and failed-gate summaries when present, and a versioned
   receipt binding the landed branch and every archived byte. Cite files,
   never proof directories; the archive tool rejects invalid citations.
   Runtime acceptance receipts still require `.orbit/evidence/` or
   `.orbit/quality-gates/` only.
6. Use `bin/orbit-session-archive --full` only for failure diagnosis,
   escalation, security or release scope, or an explicit request.
7. Update the session index and commit the archive/index. Cleanup requires
   those archive and index bytes to be tracked and committed, not merely present.
8. After the archive/index commit:
   - Session ownership is exact: the loop `Session:` line equals `feat-<slug>` and the tmux session path equals the feature worktree; LAND refuses to run inside the feature session.
   - kill the feature tmux session (`tmux kill-session -t '=feat-<slug>'`, validated by `bin/orbit-feature-finalization-check`), remove the exact clean merged worktree, then delete the exact merged feature branch.
   - Validate each cleanup mutation with
     `bin/orbit-feature-finalization-check <exact git or tmux command>`.
     After `FINALIZATION: PASS`, execute that exact cleanup command separately.
   Leave the primary checkout on updated `main` without disturbing unrelated
   files.

Compact cleanup proof is a regular `loop.md` plus a compact receipt the archive
and finalization tools validate; historical and full archives remain valid
through their legacy manifests and receipt schemas.

## Trigger-Only Loop Improvement

Clean loops create no experiment, retrospective, analyzer lane, capture lane,
metrics table, or signal record. Process improvement starts only after a failed
promoted protection, a severe preventable safety incident, a
reviewer-confirmed recurring process failure, or explicit user process
feedback. There may be one active loop experiment at a time. Keep it in
`~/shared-knowledge/projects/orbit/loop-analysis/` tagged loop-experiment with
the trigger, smallest change, one target metric derived from existing compact
receipts, a fixed window, and a revert command. Revert by default when the
target does not improve, a hard protection fails, or ordinary delivery slows
materially. Do not create generic evaluator tooling for a one-off calculation.
The prevention metric counts escaped same-surface defects after terminal PASS,
not internal commit count or autonomous pre-land rework. Hard security,
correctness, acceptance, and evidence-integrity protections are never
experiments. Historical session and signal tools serve explicit
human-requested diagnostics only, not ordinary delivery gates.

## Stop Conditions

Stop and request direction only when product intent is genuinely ambiguous,
external authority is missing, safe required infrastructure is unavailable, or
the user must make a judgment automation cannot make. A test failure, reviewer
FIX, main movement, or acceptance feedback is a state transition, not a reason
to abandon the loop.
