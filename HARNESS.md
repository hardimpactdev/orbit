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
  Pest, quality-check, Docker E2E, and Incus E2E gates.
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
12. **Root Composer scripts**: orchestrate docs-lint, tests, Pint, PHPStan,
   Rector, and E2E lanes across apps/packages

Session plans and specs stay at `docs/superpowers/`. They are not product
authority and are not the durable harness.

## Post-Feature Session Review

Before final reporting and merge-back, the orchestrator reviews the feature
thread, Solo worker sessions, reviewer output, retained terminal or PTY
evidence when applicable, verification output, and human corrections.

For non-trivial feature loops, the orchestrator creates a small local
distillation packet under `.orbit/` before merge. The packet contains only the
facts needed for review: objective, final diff or commit, evidence artifacts,
Solo process ids or summaries, reviewer findings, human corrections, and the
orchestrator's factual steering notes. Do not commit the packet.

Run a fresh-context post-feature distillation reviewer from that packet when
the feature had implementation workers, reviewer corrections, retained
terminal/PTY evidence, quality-gate artifacts, or human steering. Use
`.agents/review-personas/post-feature-distillation.md`. The reviewer recommends
`promote`, `already-covered`, `reject`, or `defer` for candidate learnings. It
does not edit code, update the harness, or decide completion.

The orchestrator adjudicates the reviewer recommendations using session
context. Distill durable repeated or costly mistakes into the smallest
appropriate guardrail target: `HARNESS.md`, `AGENTS.md`, `.agents/skills/*`,
`.agents/review-personas/*`, `harness-signals/`, deterministic tests or static
checks, command failure messages, or explicit rejection. Keep one-off local
cleanup out of the durable harness.

No durable signal is a valid result. Every final review reports evidence
reviewed, accepted durable updates, rejected or already-covered candidates,
deferred follow-ups, and the no-new-signal rationale when nothing changes.

## Merge Boundary Gate

Orbit has two merge-boundary checks:

1. `bin/orbit-feature-finalization-check` is the required explicit gate before
   merge-back or feature cleanup.
2. `.codex/hooks.json` installs a best-effort Codex `PreToolUse` hook that
   calls the same gate when Codex intercepts the tool call.

Codex hooks are useful but not a complete enforcement boundary: current Codex
`PreToolUse` support does not intercept every newer shell execution path. Do
not rely on the hook alone. Run the explicit gate before `git merge`,
`git worktree remove`, or `git branch -d`:

```bash
bin/orbit-feature-finalization-check git merge <feature-branch>
bin/orbit-feature-finalization-check git worktree remove .worktrees/<feature-branch>
bin/orbit-feature-finalization-check git branch -d <feature-branch>
```

Codex hook status is diagnostic only. Seeing `.codex/hooks.json` in the repo or
`/hooks` report `PreToolUse` as installed/active does not prove enforcement. A
hook dogfood only passes when a plain Codex-issued merge or cleanup command is
blocked before Git runs. If Git prints usage, refuses the operation itself, or
otherwise reaches command execution, treat the hook dogfood as failed and use
the explicit finalization gate for any real boundary.

Use a non-destructive command shape for hook dogfood. A good cleanup-boundary
probe is `git branch -d <feature-branch>` while that branch is still checked
out in a retained worktree: if the hook misses, Git should refuse the delete
because the branch is checked out. Do not use an invalid command as the primary
proof; it can show the hook missed the call, but it is noisier evidence than a
valid command with Git-side safety.

The gate is intentionally narrow: it only inspects git merge and
feature-cleanup boundaries, then blocks when a targeted feature worktree has no
completed `.orbit/loop.md` `Final Distillation` section.

The mechanical contract is label-based. Keep the exact Markdown bullet-label
lines from `LOOP.md.example`: `- Accepted durable updates:`,
`- Rejected or already-covered signals:`, `- Deferred follow-ups:`, and
`- No-new-signal rationale:`. At least one of those labels must contain a
meaningful outcome before merge or cleanup. Custom headings, bare label lines
without `- ` and `:`, or equivalent prose can support the explanation, but they
do not satisfy the gate by themselves.

The gate exists because feature agents repeatedly completed work, merged to
`main`, and cleaned up the worktree while leaving `.orbit/` evidence and
feature-session learnings undistilled. It does not run tests, inspect ordinary
commands, mine old sessions, or promote signals automatically. It prevents the
finalization checkpoint from being skipped before `git merge`,
`git worktree remove`, or `git branch -d` hides the local context.

If the gate blocks, do not delete `.orbit/` or bypass the merge. Review the
feature evidence, classify candidate learnings through `HARNESS_SIGNALS.md`,
fill the final-distillation outcomes in `.orbit/loop.md`, and then rerun the
`bin/orbit-feature-finalization-check`, then rerun the same git command.
For a genuinely tiny local change, the final-distillation section can record
the no-review/no-new-signal rationale explicitly.

## Active Loop Improvement

The loop is improved during the feature, not only after it. When an outer loop
improver is overseeing a dogfood feature, its job is to keep the feature owner
honest and also turn real process failures into project guardrails in the
smallest safe place. The scratchpad is guidance and backlog; it is not the
implementation of the loop.

When a feature exposes a repeated or costly harness gap, the loop improver
classifies it immediately:

- If the gap blocks the feature contract, steer the feature owner or reviewer
  inside the feature worktree.
- If the gap belongs to durable repo process, patch the harness, skills,
  personas, or signal records in a separate harness worktree when the active
  feature worktree is under review or otherwise should stay stable.
- If the gap is only a one-off local cleanup, record the rejection rationale in
  `.orbit/loop.md` or the feature report instead of adding a new rule.

Waiting for a feature owner, reviewer, retained terminal, or quality gate is
active loop time. Use it to inspect the latest worker evidence, search
`harness-signals/` for matching process failures, update the feature scratchpad
when durable state changed, or patch a small guardrail in a separate harness
worktree. Do not fill waiting time with repeated steering unless the worker is
blocked, idle without useful progress, or drifting from the contract.

Do not wait for the user to ask whether the loop should be improved. When the
user has to repeat the same correction, treat that as a loop signal and either
patch the durable guardrail, explain why an existing guardrail already covers
it, or create a scoped follow-up with an owner and trigger.

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
  roadmap that links back to the source scratchpad.
- Solo todos are optional assignment cards. Create them only when a slice needs
  asynchronous delegation, queueing, or explicit tracking outside the active
  orchestrator thread. If a todo exists, keep it thin: point to the scratchpad
  and name the slice instead of copying the whole loop contract.

One feature maps to one implementation worktree by default. Build related
slices in that same worktree and merge to `main` only after the whole feature
passes the feature-level final gate, including the agreed E2E lane. Do not
merge internal slices independently unless a slice is explicitly split into a
separate feature with its own final gate.

Within a feature worktree, `.orbit/loop.md` is the current-slice contract, not
the feature history. Rewrite it when the next slice starts. Keep prior slice
outcomes in the feature scratchpad and the actual code history in Git. The top
of `.orbit/loop.md` should name the feature scratchpad, summarize completed
slices in one line each, and identify the current slice so a worker knows the
branch may already contain earlier feature work.

If a multi-slice feature reaches worker dispatch without a feature roadmap
scratchpad link, pause the feature loop and create the scratchpad before
continuing. Classify the miss in `.orbit/loop.md` final distillation and update
`harness-signals/` only when existing guidance did not make the gate clear.

## Feature Cleanup

Cleanup has two boundaries.

- Merge cleanup happens after the feature branch is committed, verified, and
  merged into `main`. Remove the completed worktree and merged branch unless
  the user explicitly asks to preserve them.
- Feature completion cleanup happens only after the user confirms the live
  topology works as expected, or explicitly says the feature can be considered
  complete. Then archive the feature scratchpad, close or resolve related Solo
  todos, and stand down related Solo agents or retained terminals.

Keep cleanup scoped to the feature. Do not archive unrelated scratchpads, close
unrelated todos, or stop unrelated agents just because they are in the same Solo
project. Before archiving Solo state, make sure the scratchpad or todo records
the merge commit, final verification, live/user acceptance when applicable, and
any preserved follow-up. If a worktree, scratchpad, todo, or agent must remain
open, report the reason and owner.

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

For quality-gate optimization, split the work into separate lanes by default:
in-memory/Pest and `composer quality-check`, Docker E2E, and Incus E2E. Docker
and Incus work may run at the same time when provider capacity allows. Do not
optimize all in-memory/app/package checks first while provider lanes sit idle;
start the independent provider investigation in parallel and reconcile the
lane reports afterward. Do not overlap aggregate `composer quality-check` with
active provider E2E unless the shared E2E support state is proven isolated; run
that aggregate gate after provider workers are idle.

## Solo Role Matrix

Solo is the worker substrate for Orbit repo development. Use it to split work
only when ownership can stay clear.

| Role | Default Agent | Owns | Does Not Own |
|------|---------------|------|--------------|
| Feature orchestrator | Any capable LLM surface; Codex is the usual default | Done Contract, worktree, worker prompts, scope control, review, verification, final report, next step | Blind implementation or accepting worker output without inspection |
| Implementation worker | Solo-managed worker; Grok is the usual default | Bounded PHP, CLI, Pest, E2E, and app/package code slices | Final commit, merge-back, release, broad refactors, unrelated dirty files |
| Documenter / librarian worker | Claude | Documentation contracts, command docs, docs-first handoffs, focused docs drift analysis | Final product decision, code implementation, broad audit unless requested |
| CLI verifier | Codex or another smart model | PTY capture, retained VM command proof, JSON/human output evidence | Product redefinition or release approval |
| Post-feature distillation reviewer | Fresh Solo-managed reviewer; Claude preferred when available | Candidate learning classification from the distillation packet and changed diff | Implementation, harness edits, merge approval, or final promotion decisions |
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
and fixers to their owned files; broad dirty-file tools such as `pint --dirty`,
broad Rector, or aggregate fixers belong to the feature owner after worker
diffs are reconciled.

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
| Documentation-heavy feature | `updating-documentation`, `implementing-features`; optional Claude documenter/librarian worker | Product docs, command docs, product-decision ledger, changed tests | Docs contract review, then focused Pest/E2E owned by implementation | `.agents/review-personas/docs-librarian.md` before accepting docs contract | Record unclear authority, repeated docs/code mismatch, or docs-worker handoff gaps | Docs contract is unstable, authority conflict needs a decision, or docs/code workers disagree |
| Quality-gate failure or slowdown | `quality-gate-triage`, plus `pest-testing`, `e2e-verification-lanes`, or `cli-output-pty-capture` by lane | `apps/docs/content/testing/README.md`, `quality-gates.md`, `in-memory/performance.md`, `e2e/environment.md`, `e2e/performance.md` | Inspect existing evidence under `.orbit/quality-gates/` and `.orbit/evidence/`; do not rerun expensive gates just to classify | Owner/human only after classification points at product behavior | Record recurring flakes, missing baselines, or confusing lane failures | Aggregate provision command, live-node mutation, or product fix before classification |
| Post-feature distillation | `.agents/review-personas/post-feature-distillation.md`, then `implementing-features` for orchestrator adjudication | `HARNESS.md`, `HARNESS_SIGNALS.md`, `harness-signals/README.md`, `.orbit/loop.md`, changed diff and evidence packet | No tests by default; run `git diff --check`, discoverability `rg`, docs-lint when product docs changed | Fresh reviewer recommendation for non-trivial loops; orchestrator owns final decision | Promote only real repeated or costly mistakes with a counterfactual guardrail | Guardrail added from weak evidence, no rejected/no-op rationale, or reviewer asked to implement |
| CLI command | `command-designer`, `cli-output-pty-capture` when human rendering or cadence matters, `implementing-features` | Command docs under `apps/docs/content/`, command tests, `AGENTS.md` | Focused Pest first; E2E next; PTY frame capture and reviewer analysis before human UX review; retained Incus VM Solo-terminal gate before live or release-candidate deploy | `.agents/review-personas/cli-command.md` or human for UX/product contract changes | Search signals, update/create record for repeated command-contract issues | No failing/passing command proof, no retained VM proof when CLI behavior needs it, no PTY frame analysis before human UX review, or live topology would be touched without approval |
| Gateway API | `implementing-features`, Laravel/PHP skills | `apps/docs/content/**`, gateway routes/controllers/tests | Focused gateway Pest; E2E when behavior crosses node/topology boundaries | API/product reviewer when contract changes | Record repeated API contract or routing mistakes | API docs and implementation disagree, or authorization/security impact is unclear |
| Provisioning/live-node | `e2e-verification-lanes`, `implementing-features` | `apps/docs/content/testing/README.md`, provisioning docs, product decisions | Prepared-topology lane, retained topology inspection, then approved live-node proof | Human before live mutation | Always capture topology/node evidence; record expensive or repeated failures | Provider pool/auth is ambiguous, role target is unclear, or live mutation lacks approval |
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
changes terminal behavior. Do not spend full E2E on every internal slice by
default. Run the agreed E2E lane as the feature-level merge gate, or earlier
only when the active slice itself cannot be judged without topology behavior.

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
   code
2. **Verify**: run the narrowest useful checks for the change type (Pest,
   quality-check, E2E when touched)
3. **Triage**: when something fails or review finds a gap, identify the missing
   context or guardrail
4. **Distill**: encode the lesson into durable text: skill, doc, test message,
   or product-decision entry
5. **Handoff**: report the completed evidence and name the next slice or next
   concrete step. Do not make the user ask what comes next.

The harness surfaces the map; the loop turns session signals into better
guardrails.
