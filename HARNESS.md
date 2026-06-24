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
- Reviewer-persona framework

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

After a feature completes, the orchestrator reviews the feature thread, Solo
worker sessions, reviewer output, retained terminal or PTY evidence when
applicable, verification output, and human corrections.

Distill durable repeated mistakes or missing context into the smallest
appropriate sink: `HARNESS.md`, `AGENTS.md`, `.agents/skills/*`,
`.agents/review-personas/*`, `harness-signals/`, deterministic tests or static
checks, command failure messages, or explicit rejection. Keep one-off local
cleanup out of the durable harness.

## Feature Slices

Use the least durable state that can keep the work coherent.

- A small request can start directly in one feature worktree with one
  `.orbit/loop.md`.
- A request that is too large for one implementation slice gets one lightweight
  Solo scratchpad. The scratchpad records feature intent, rough slice order,
  slice outcomes, open decisions, and the final verification gate. It is not a
  full spec and not a command log.
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

## Solo Role Matrix

Solo is the worker substrate for Orbit repo development. Use it to split work
only when ownership can stay clear.

| Role | Default Agent | Owns | Does Not Own |
|------|---------------|------|--------------|
| Feature orchestrator | Any capable LLM surface; Codex is the usual default | Done Contract, worktree, worker prompts, scope control, review, verification, final report, next step | Blind implementation or accepting worker output without inspection |
| Implementation worker | Solo-managed worker; Grok is the usual default | Bounded PHP, CLI, Pest, E2E, and app/package code slices | Final commit, merge-back, release, broad refactors, unrelated dirty files |
| Documenter / librarian worker | Claude | Documentation contracts, command docs, docs-first handoffs, focused docs drift analysis | Final product decision, code implementation, broad audit unless requested |
| CLI verifier | Codex or another smart model | PTY capture, retained VM command proof, JSON/human output evidence | Product redefinition or release approval |
| Overflow lane | `mini` through Solo/SSH | Independent feature, review, verification, or investigation work | Shared mutable state, generic E2E host assumptions, uncoordinated merge authority |

The active feature-owner thread is the source of work. It can run in Codex CLI,
the Codex app, Claude, or another capable LLM surface. Spawned workers and
retained verification terminals run through Solo so ownership, process ids, and
terminal proof remain inspectable. Workers receive the active Done Contract,
worktree path, owned files or domains, stop and pivot conditions, and reporting
shape. If those boundaries are hard to state, use one worker serially instead
of parallel workers.

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
