# Orbit Repo Harness

Orbit is an LLM-first monorepo. The repository harness lives at the monorepo
root. It is the durable surface agents use to discover how to work on this
codebase.

## Scope

This harness governs **repo development only**: how agents plan, implement,
verify, and hand off changes to the Orbit repository. It does not define
customer/product runtime behavior, fleet operations, or how Orbit helps agents
operate customer workspaces. Product contracts live under
`apps/docs/content/`. Product direction changes live in the root
`PRODUCT_DECISIONS.md` intent ledger.

## Harness vs Loop

**Harness** is the durable context map: what good looks like, which files to
read, which skills apply, and which verification lanes exist. It steers agents
without micromanaging every step.

**Loop** is the operational feedback cycle: run work, observe signals
(failures, reviews, drift), triage what went wrong, distill durable guardrails
back into the harness. The loop improves the harness over time.

Feature loops have three top-level outcomes:

- `complete`: the feature is verified and no unresolved blocker remains.
- `blocked`: required evidence or acceptance work cannot be completed and the
  blocker cannot be resolved inside the current slice.
- `complete + loop improvement`: the feature is verified, and a real recurring
  or costly process lesson was promoted into a durable guardrail.

Candidate classifications such as `promote`, `already-covered`, `reject`, and
`defer` are supporting detail for the final distillation. They do not replace
the feature-loop outcome.

## Non-Goals

The root harness is intentionally incremental. Not in scope yet:

- Autonomous merge or reviewer-agent automation
- Customer/product harness (fleet/workspace agent docs)
- Automation loop (nightly distillation, continuous session mining)
- Reviewer-persona framework beyond the focused personas justified by real
  feature-loop signals

`LOOP.md.example`, `.orbit/loop.md`, `.orbit/quality-gates/`,
`.orbit/evidence/`, and `HARNESS_SIGNALS.md` define the manual feedback-loop
layer. Later slices may add or refine reviewer personas and automation only
after the manual loop is stable.

## Worktree-Local State

Use root `.orbit/` as the gitignored home for ephemeral state in the current
worktree. This is repository-development state for the checkout, not product
runtime state inside app workspaces or nodes.

- `.orbit/loop.md`: current-slice state copied from `LOOP.md.example`.
- `.orbit/quality-gates/`: local timing, analyzer, and triage reports for
  Pest, quality-check, Docker E2E, and Incus E2E gates when those commands are
  explicitly run.
- `.orbit/evidence/`: retained local evidence such as command transcripts,
  PTY summaries, screenshots, or pointers to Solo terminals and topology ids.

Do not commit `.orbit/`. Commit only the durable guardrail that absorbs a
recurring signal: harness docs, skills, review personas, product/testing docs,
tests, or a curated `harness-signals/` record.

## Agent Discovery Path

Start at the monorepo root and read in this order:

1. **`AGENTS.md`**: repo shape, authority chain, verification commands,
   worktree workflow
2. **`HARNESS.md`**: this file; repo harness anchor
3. **`LOOP.md.example`**: local loop-state template; copy it to
   `.orbit/loop.md` for non-trivial active work
4. **`.orbit/loop.md`**: current slice state when present; never treat
   absence in a fresh checkout as a product gap
5. **`HARNESS_SIGNALS.md`**: signal-to-guardrail-target map for the feedback loop
6. **`harness-signals/`**: curated signal records to search for prior
   occurrences, guardrail changes, and recurrence checks
7. **`.agents/skills/`**: domain procedures activated just-in-time per change
   type
8. **`.agents/review-personas/`**: focused review checklists activated by the
   routing table after implementation evidence exists
9. **`PRODUCT_DECISIONS.md`**: dated product intent ledger for direction
   changes and reversals
10. **`apps/docs/content/`**: product authority (behavior contracts, not
   repo-dev procedures)
11. **`bin/orbit-prepare-worktree`**: create and bootstrap isolated
   implementation worktrees
12. **Root Composer scripts**: orchestrate docs-lint, tests, Mago, Rector, and
   E2E lanes across apps/packages

Session plans and specs stay at `docs/superpowers/`. They are not product
authority and are not the durable harness.

## Post-Feature Session Review

Before final reporting and merge-back, the orchestrator reviews the feature
thread, Solo worker sessions, reviewer output, retained terminal or PTY
evidence when applicable, verification output, and human corrections.

For non-trivial feature loops, `.orbit/loop.md` is the canonical local final
packet. It should point to evidence artifacts rather than duplicate everything:
objective, final diff or commit, Solo process ids or summaries, reviewer
findings, verification output, human corrections, and the orchestrator's
factual steering notes. Scratchpads, reviewer output, and final reports point
back to `.orbit/loop.md` instead of replacing it. Do not commit the packet.

Add candidate signals to `.orbit/loop.md` as they appear. The final review
should classify an already-collected packet, not reconstruct the session from
scattered artifacts after the fact.

Run a fresh-context post-feature analyzer from that packet when the feature had
implementation workers, reviewer corrections, retained terminal/PTY evidence,
quality-gate artifacts, human steering, or guardrail decisions. Use
`.agents/review-personas/post-feature-analyzer.md`. The analyzer reviews the
orchestrator's Codex/Solo session messages and worktree artifacts, then reports
whether the loop was performed properly and whether guardrails were missed,
redundant, correctly omitted, or aimed at the wrong target. It does not edit
code, update the harness, or decide completion.

The orchestrator adjudicates the reviewer recommendations using session
context. Start by eliminating non-signals: one-off handoffs, lessons already
covered by current project guidance or enforcement, reviewer findings fixed
before merge, stale historical artifacts, and ordinary feature work. Distill
only durable repeated or costly mistakes into the smallest appropriate
guardrail target: `HARNESS.md`, `AGENTS.md`, `.agents/skills/*`,
`.agents/review-personas/*`, `harness-signals/`, deterministic tests or static
checks, command failure messages, or explicit rejection.

Before adding a new guardrail, check whether the lesson has already landed in
the current project as code, tests, docs, skills, signal records, or clearer
failure messages. If a later slice already absorbed the lesson, classify the
candidate as `already-covered` and name the existing coverage instead of
creating duplicate guidance.

Feature completion and loop improvement are separate decisions. No durable
signal is a valid `complete` result when the feature is verified and the final
distillation records why no new signal remains. Every final review reports the
loop outcome, evidence reviewed, accepted durable updates, rejected or
already-covered candidates, deferred follow-ups, and the no-new-signal
rationale when nothing changes.

## Merge Boundary Gate

This section is the authority for feature merge and cleanup boundaries. Other
instructions should point here instead of restating this policy.

`bin/orbit-feature-finalization-check` is the executable gate. The Codex and
Claude Code `PreToolUse` hooks call the same gate when those hook surfaces
intercept a boundary command, but hook status is diagnostic only. Use the helper
directly before real merge or cleanup boundaries; run it with no arguments for
current command usage.

Cleanup commands are valid only after the post-feature signal audit is complete
or the user explicitly approves cleanup. Until then, leave the completed
feature worktree and branch intact.

The gate is intentionally narrow: it only inspects git merge and
feature-cleanup boundaries, then blocks when a targeted feature worktree has no
completed `.orbit/loop.md` `Final Distillation` section, when the loop outcome
is not exactly `complete` or `complete + loop improvement`, when required
verification rows are missing, or when required verification is still recorded
as blocked, pending, skipped, missing, deferred, unresolved, or not run. It
derives the required proof from the branch diff and reads existing
`.orbit/quality-gates/` artifacts instead of rerunning gates.

The mechanical contract is label-based. Keep the exact Markdown bullet-label
lines from `LOOP.md.example`: `- Loop outcome:`, `- Required verification:`,
`- Accepted durable updates:`, `- Rejected or already-covered signals:`,
`- Deferred follow-ups:`, and `- No-new-signal rationale:`. At least one of the
signal-outcome labels must contain a meaningful outcome before merge or cleanup.
Custom headings, bare label lines without `- ` and `:`, or equivalent prose can
support the explanation, but they do not satisfy the gate by themselves.

Required verification rows use the status-first shape and must include retained
topology proof and `composer quality-check`:
`- Retained topology proof: passed | blocked | not applicable - <evidence or reason>`.
If the feature required a lane and it is blocked, the feature outcome is
`blocked`; do not write `complete` with a deferred verification follow-up.
Docs-only diffs can satisfy the gate with a successful `composer docs-lint` or
broader `composer quality-check` artifact. Other diffs require successful
`composer quality-check` artifact evidence. Production PHP diffs additionally
require retained topology proof to be `passed`. A passed retained topology row
names the topology id/kind, checkout roles or inspected nodes, exact command,
and captured terminal/session or artifact evidence. Stale-commit and
timing-threshold warnings remain the job of
`composer quality-gate:final-check` and the quality-gate triage skill.

The gate exists because feature agents repeatedly completed work, merged to
`main`, and cleaned up the worktree while leaving `.orbit/` evidence and
feature-session learnings undistilled. It does not run tests, inspect ordinary
commands, mine old sessions, or promote signals automatically.

If the gate blocks, do not delete `.orbit/` or bypass the merge. Review the
feature evidence, classify candidate learnings through `HARNESS_SIGNALS.md`,
fill the final-distillation outcomes in `.orbit/loop.md`, rerun the helper, and
then rerun the same git command.
For a genuinely tiny local change, the final-distillation section can record
the no-review/no-new-signal rationale explicitly.

Historical worktrees that only contain gitignored `.orbit/` evidence are
cleanup targets, not automatic harness-improvement sources. If the useful
lesson already landed elsewhere, fill or report the final distillation as
`already-covered` or `no-new-signal` instead of promoting another guardrail.
Regular loop improvement comes from the active feature's evidence. Broad
history or worktree mining is a separate explicitly requested workflow, not the
default finalization path.

## Post-Feature Signal Audit

Normal feature work does not need an outer loop-improver watcher. Kick off the
feature implementer through the implementation workflow, let it complete the
feature loop, preserve the worktree and `.orbit/` artifacts, then run the
post-feature analyzer against the implementation session and artifacts.

The analyzer is read-only. It inspects the feature orchestrator's Codex/Solo
session messages, `.orbit/loop.md`, `.orbit/evidence/`,
`.orbit/quality-gates/`, Solo scratchpads, worker and reviewer reports,
retained terminal or PTY evidence, verification output, human corrections, and
the final diff or commit. It reports whether the loop was proper, flawed, or
blocked by missing evidence.

The analyzer checks guardrail decisions instead of supervising live work:

- `correct-noop`: no durable guardrail was needed, and the evidence supports
  that result.
- `missed`: a durable guardrail should have been added or tightened.
- `redundant`: a guardrail was added even though existing guidance or
  enforcement already covered it.
- `wrong-target`: a real signal was promoted, but the target is too broad,
  undiscoverable, or not verifiable.
- `defer`: the concern may be real, but evidence, ownership, or recurrence risk
  is not clear enough yet.

The feature owner or human adjudicates the analyzer report. Patch Orbit only
when the report identifies a concrete recurring or costly signal, the smallest
guardrail target is clear, and the verification for that target is reachable.
If the report finds only local cleanup, existing coverage, stale artifacts, or
ordinary feature work, record the no-new-signal rationale and do not add a new
rule.

## Feature Slices

Use the least durable state that can keep the work coherent.

- A small request can start directly in one feature worktree with one
  `.orbit/loop.md`.
- A request that is too large for one implementation slice gets one lightweight
  Solo scratchpad. The scratchpad records feature intent, rough slice order,
  slice outcomes, open decisions, and the final verification gate. It is not a
  full spec and not a command log.
- Scratchpad creation is a pre-dispatch gate for multi-slice features. Create
  or identify the feature roadmap before preparing the implementation worktree
  or spawning workers, then put its `solo://` URL at the top of `.orbit/loop.md`
  and in worker prompts. If the work executes in a different Solo project or
  machine from the source scratchpad, create a reachable execution-project
  roadmap that links back to the source scratchpad and carries the source
  roadmap substance: feature request, slice order, current-slice acceptance
  criteria, deferred slices, and open decisions. A link-only execution
  scratchpad is not enough because local workers and reviewers may not be able
  to read the source project.
- Solo todos are optional assignment cards. Create them only when a slice needs
  asynchronous delegation, queueing, or explicit tracking outside the active
  orchestrator thread. If a todo exists, keep it thin: point to the scratchpad
  and name the slice instead of copying the whole loop contract.

One feature maps to one implementation worktree by default. Build related
slices in that same worktree and merge to `main` only after the whole feature
passes the feature-level final gate, including retained topology proof when the
diff requires topology evidence. Do not
merge internal slices independently unless a slice is explicitly split into a
separate feature with its own final gate.

Within a feature worktree, `.orbit/loop.md` is the current-slice contract, not
the feature history. Rewrite it when the next slice starts. Keep prior slice
outcomes in the feature scratchpad and the actual code history in Git. The top
of `.orbit/loop.md` should name the feature scratchpad, summarize completed
slices in one line each, and identify the current slice so a worker knows the
branch may already contain earlier feature work.

If a multi-slice feature reaches worker dispatch without a feature roadmap
scratchpad link, or with only a thin cross-project link that does not mirror the
roadmap substance into the execution project, pause the feature loop and fix the
scratchpad before continuing. Classify the miss in `.orbit/loop.md` final
distillation and update `harness-signals/` only when existing guidance did not
make the gate clear.

## Feature Cleanup

Merge and cleanup are separate boundaries.

- Merge happens after the feature branch is committed, verified, final
  distillation is filled, and the merge boundary gate passes. Leave the
  completed feature worktree and branch intact after merge by default.
- Post-feature signal audit happens after merge while the worktree is still
  available. Review `.orbit/loop.md`, `.orbit/evidence/`,
  `.orbit/quality-gates/`, Solo scratchpads, reviewer output, and retained
  terminal or PTY artifacts. Confirm accepted, rejected, already-covered, and
  deferred signals were processed and that no harness signal was lost.
- Worktree cleanup happens only after that audit is complete or the user
  explicitly approves cleanup. Follow the Merge Boundary Gate above before
  running cleanup.
- Feature completion cleanup happens only after the user confirms the live
  topology works as expected, or explicitly says the feature can be considered
  complete. Then archive the feature scratchpad, close or resolve related Solo
  todos, and stand down related Solo agents or retained terminals.

Keep cleanup scoped to the feature. Do not archive unrelated scratchpads, close
unrelated todos, or stop unrelated agents just because they are in the same Solo
project. Before archiving Solo state, make sure the scratchpad or todo records
the merge commit, final verification, live/user acceptance when applicable, and
any preserved follow-up. If a worktree, scratchpad, todo, or agent remains open
for post-analysis, report the reason and owner.

## Parallelization Gate

Before executing a goal, feature, or quality-gate tuning pass, the orchestrator
must decide what can run in parallel. The default is parallel dispatch for
independent slices. Serial execution is a justified exception, not the default
shape.

Being part of one goal, feature, or harness-improvement effort is not a
dependency. A slice is serial only when it needs another slice's result, edits
the same owned files, mutates the same provider or temp state, exceeds provider
capacity, or has an unavoidable merge-order constraint.

Record the decision in `.orbit/loop.md`, the feature scratchpad, or the worker
plan before workers start:

- candidate slices or lanes
- owned files and domains
- shared provider resources
- shared temp or local state paths
- dependencies on another lane's result
- merge-order constraints
- lanes intentionally deferred, with the concrete reason and owner

For quality-gate optimization, split in-memory/Pest and `composer quality-check`
work into separate lanes by default. Do not spawn Docker or Incus E2E workers;
E2E artifacts are read-only evidence unless the user explicitly runs a
`composer test:e2e*` command from a shell. Do not overlap aggregate
`composer quality-check` with active user-run provider E2E unless the shared
E2E support state is proven isolated; run that aggregate gate after the
user-run provider command is idle.

## Solo Role Matrix

Solo is the worker substrate for Orbit repo development. Use it to split work
only when ownership can stay clear.

| Role | Default Agent | Owns | Does Not Own |
|------|---------------|------|--------------|
| Feature orchestrator | Any capable LLM surface; Codex is the usual default | Done Contract, worktree, worker prompts, scope control, review, verification, final report, next step | Blind implementation or accepting worker output without inspection |
| Implementation worker | Solo-managed worker; Grok is the usual default | Bounded PHP, CLI, Pest, E2E, and app/package code slices | Final commit, merge-back, release, broad refactors, unrelated dirty files |
| Documenter / librarian worker | Claude | Documentation contracts, command docs, docs-first handoffs, focused docs drift analysis | Final product decision, code implementation, broad audit unless requested |
| CLI verifier | Codex or another smart model | PTY capture, retained VM command proof, JSON/human output evidence | Product redefinition or release approval |
| Post-feature analyzer | Fresh Solo-managed analyzer; Claude preferred when available | Read-only review of Codex/Solo session messages, `.orbit` artifacts, verification evidence, final diff, and guardrail decisions | Live steering, implementation, harness edits, merge approval, cleanup, or final promotion decisions |
| Overflow lane | `mini` through Solo/SSH | Independent feature, review, verification, or investigation work | Shared mutable state, generic E2E host assumptions, uncoordinated merge authority |

The active feature-owner thread is the source of work. It can run in Codex CLI,
the Codex app, Claude, or another capable LLM surface. Spawned workers and
retained verification terminals run through Solo so ownership, process ids, and
terminal proof remain inspectable. Workers receive the active Done Contract,
worktree path, owned files or domains, stop and pivot conditions, and reporting
shape. Worktree-scoped Solo workers must confirm `pwd` and
`git branch --show-current` before broad reads or edits; if the spawned agent
opens at the project root, relaunch through a Solo terminal that first `cd`s
into the worktree. If those boundaries are hard to state, use one worker
serially instead of parallel workers.

Before execution, use the parallelization gate above. A serial plan for isolated
goals, slices, or lanes is incomplete unless it names the concrete dependency,
shared state, provider capacity limit, or merge-order reason. If two tasks have
disjoint ownership and neither needs the other's result, dispatch them in
parallel through Solo by default. Serialize only when tasks edit the same files,
mutate the same provider resources, depend on a prior result, or cannot name a
clear merge order. In parallel-worker mode, workers must also scope formatters
and fixers to their owned files; broad Mago formatting, broad Rector, or
aggregate fixers belong to the feature owner after worker diffs are reconciled.

Documentation-heavy work may start with a Claude documenter/librarian worker.
Code implementation can run after the feature owner accepts the docs contract as
stable enough. Docs and code may proceed in parallel only when the product
contract is settled, ownership is disjoint, and the feature owner owns
reconciliation before commit.

## Root Routing

Use this table to pick the smallest workflow that can prove the change.

| Surface | Skill | Authority Docs | Test Lane | Reviewer Needed | Loop Depth | Hard Stop |
|---------|-------|----------------|-----------|-----------------|------------|-----------|
| Docs-only | `updating-documentation`; `auditing-docs-drift` only for an explicit consistency scan | `apps/docs/content/**`, `PRODUCT_DECISIONS.md`, or root harness docs depending on scope | `composer docs-lint` when product docs change; otherwise `git diff --check` | `.agents/review-personas/docs-librarian.md` or human if authority changes | Record only repeated drift | Product docs conflict with latest product decision |
| Documentation-heavy feature | `updating-documentation`, `implementing-features`; optional Claude documenter/librarian worker | Product docs, command docs, product-decision ledger, changed tests | Docs contract review, then focused Pest owned by implementation | `.agents/review-personas/docs-librarian.md` before accepting docs contract | Record unclear authority, repeated docs/code mismatch, or docs-worker handoff gaps | Docs contract is unstable, authority conflict needs a decision, or docs/code workers disagree |
| Quality-gate failure or slowdown | `quality-gate-triage`, plus `pest-testing`, `e2e-verification-lanes`, or `cli-output-pty-capture` by lane | `apps/docs/content/testing/README.md`, `quality-gates.md`, `in-memory/performance.md`, `e2e/environment.md`, `e2e/performance.md` | Inspect existing evidence under `.orbit/quality-gates/` and `.orbit/evidence/`; do not rerun expensive gates just to classify | Owner/human only after classification points at product behavior | Record recurring flakes, missing baselines, or confusing lane failures | Aggregate provision command, live-node mutation, or product fix before classification |
| Post-feature analysis | `.agents/review-personas/post-feature-analyzer.md`, then `implementing-features` for orchestrator adjudication | `HARNESS.md`, `HARNESS_SIGNALS.md`, `harness-signals/README.md`, `.orbit/loop.md`, `.orbit/evidence/`, `.orbit/quality-gates/`, Codex/Solo session messages, changed diff, and evidence packet | No tests by default; run `git diff --check`, discoverability `rg`, docs-lint when product docs changed | Fresh analyzer report for non-trivial loops; orchestrator owns final decision | Promote only real repeated or costly mistakes with a counterfactual guardrail; reject missed, redundant, or wrong-target guardrails clearly | Guardrail added from weak evidence, no rejected/no-op rationale, analyzer asked to implement, or session/artifacts missing enough evidence to judge |
| CLI command | `command-designer`, `cli-output-pty-capture` when human rendering or cadence matters, `implementing-features` | Command docs under `apps/docs/content/`, command tests, `AGENTS.md` | Focused Pest first; retained topology proof; PTY frame capture and reviewer analysis before human UX review | `.agents/review-personas/cli-command.md` or human for UX/product contract changes | Search signals, update/create record for repeated command-contract issues | No failing/passing command proof, no retained topology proof when CLI behavior needs it, no PTY frame analysis before human UX review, or live topology would be touched without approval |
| Gateway API | `implementing-features`, Laravel/PHP skills | `apps/docs/content/**`, gateway routes/controllers/tests | Focused gateway Pest; retained topology proof when behavior crosses node/topology boundaries | API/product reviewer when contract changes | Record repeated API contract or routing mistakes | API docs and implementation disagree, or authorization/security impact is unclear |
| Provisioning/live-node | `implementing-features`; `e2e-verification-lanes` only for existing artifact triage or manual command reference | `apps/docs/content/testing/README.md`, provisioning docs, product decisions | Retained topology inspection, then approved live-node proof | Human before live mutation | Always capture topology/node evidence; record expensive or repeated failures | Provider pool/auth is ambiguous, role target is unclear, or live mutation lacks approval |
| Release | `release` | Release skill, changelog/version files, product docs touched by release | Release gates: doctor before, `update:all`, doctor after, `node:list`, plus exception checks | Human before tag, publish, or merge/push beyond the approved release step | Record release-gate surprises and recurring fleet drift | Any release gate fails or approval boundary is not explicit |
| App/package shared core | `implementing-features`, Laravel/PHP skills | `packages/core/**`, affected app docs/tests | Package tests plus focused impacted app tests; broaden to `composer quality-check` for shared contracts | Owner/reviewer for cross-app behavior | Record boundary leaks or repeated shared-contract misses | Affected apps are unknown, or shared behavior lacks targeted coverage |

## Done Contract

For non-trivial work, the feature orchestrator fills the active slice contract
before implementation. Keep it short enough to copy into `.orbit/loop.md`.
Workers may challenge the contract, but they must not silently weaken scope,
evidence, reviewer checks, stop conditions, or pivot conditions.

When a request includes concrete output samples, command transcripts, UI
examples, or negative examples, the Done Contract keeps those raw examples or a
precise pointer to them. Any decomposition into slices must name which parts of
the raw request are in the current slice, which are deferred, and why deferral
does not invalidate the acceptance contract. A reviewer finding that matches
the original raw request is a contract gap, not an optional enhancement, unless
the feature owner had explicitly deferred it before implementation began.

```markdown
Current slice:

Done when:

Evidence:

Reviewer checks:

Stop if:

Pivot if:
```

`Stop if` means the agent should halt and hand back because continuing would be
unsafe or outside scope. `Pivot if` means the agent can continue, but should
change approach instead of repeatedly patching the same path.

The contract is not ceremony. It defines when the agent should continue, when it
should stop, which approach changes are allowed, and which evidence is enough
for handoff.

## Slice Verification

Validate each slice with the narrowest checks that keep the feature branch
honest: focused Pest, docs-lint, static checks, or PTY proof when the slice
changes terminal behavior. Do not spend E2E on feature slices by default. The
finalization gate derives the feature-level proof from the final
branch diff: docs-only changes need docs-lint evidence, non-docs changes need
quality-check evidence, and production PHP changes need retained topology proof.
Run retained topology proof when the active slice cannot be judged without real
topology behavior.

When retained topology proof is required for acceptance and cannot be completed,
the feature loop halts if the blocker cannot be resolved inside the current
slice. Do not finalize, merge, clean up, or mine final loop improvements while
required retained topology proof is still blocked. Record the exact blocker,
owner, and unblock condition in `.orbit/loop.md` under `Required verification`, set the loop
outcome to `blocked`, then hand back unresolved work.

Treat this as the `blocked` feature-loop outcome, not as a candidate learning.
It becomes a loop-improvement signal only when the reason for the retained
topology proof block reveals a recurring process gap.

## Review Scope

Reviewer personas inspect the changed files, named authority docs, focused
tests, implementation report, and captured evidence for the slice under review.
They may read project-wide patterns from `AGENTS.md`, `HARNESS.md`, skills, and
authority docs to evaluate the diff, but they do not scan or relitigate the
whole project unless the user explicitly asks for a broad audit.

Broad documentation audits are a separate workflow. Use
`auditing-docs-drift` for explicit contradiction, drift, stale terminology, or
anchor-sweep requests; do not smuggle that full-repo audit into routine feature
review.

## Loop Stack

Orbit repo development follows a simple loop stack:

1. **Implement**: scoped change in an isolated worktree; align docs, tests, and
   code.
2. **Verify**: run the narrowest useful checks for the change type (Pest,
   quality-check, retained topology proof when touched).
3. **Triage**: when something fails or review finds a gap, identify the missing
   context or guardrail.
4. **Distill**: record candidate signals in `.orbit/loop.md` as they appear,
   then classify them as `promote`, `already-covered`, `reject`, or `defer`.
5. **Handoff**: report `complete`, `blocked`, or `complete + loop improvement`
   with evidence and the next concrete step. Do not make the user ask what
   comes next.

The harness surfaces the map; the loop turns session signals into better
guardrails.
