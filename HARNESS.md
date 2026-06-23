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
- Eval runner or `evalc/` directory
- Automation loop (nightly distillation, continuous session mining)
- Reviewer-persona framework

`LOOP.md.example`, ignored `LOOP.md`, and `HARNESS_SIGNALS.md` define the manual
feedback-loop layer. Later slices may add reviewer personas, automation, and
`evalc/` only after the manual loop is stable.

## Agent Discovery Path

Start at the monorepo root and read in this order:

1. **`AGENTS.md`**: repo shape, authority chain, verification commands,
   worktree workflow
2. **`HARNESS.md`**: this file; repo harness anchor
3. **`LOOP.md.example`**: local loop-state shape; copy it to ignored
   `LOOP.md` for non-trivial active work
4. **`LOOP.md`**: current worktree state when present; never treat absence in a
   fresh checkout as a product gap
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

## Root Routing

Use this table to pick the smallest workflow that can prove the change.

| Surface | Skill | Authority Docs | Test Lane | Reviewer Needed | Loop Depth | Hard Stop |
|---------|-------|----------------|-----------|-----------------|------------|-----------|
| Docs-only | `updating-documentation` | `apps/docs/content/**`, `PRODUCT_DECISIONS.md`, or root harness docs depending on scope | `composer docs-lint` when product docs change; otherwise `git diff --check` | Human if authority changes | Record only repeated drift | Product docs conflict with latest product decision |
| CLI command | `command-designer`, `cli-output-pty-capture` when human rendering or cadence matters, `implementing-features` | Command docs under `apps/docs/content/`, command tests, `AGENTS.md` | Focused Pest first; E2E next; PTY capture for terminal UX/cadence issues; retained Incus VM Solo-terminal gate before live or release-candidate deploy | `.agents/review-personas/cli-command.md` or human for UX/product contract changes | Search signals, update/create record for repeated command-contract issues | No failing/passing command proof, no retained VM proof when CLI behavior needs it, or live topology would be touched without approval |
| Gateway API | `implementing-features`, Laravel/PHP skills | `apps/docs/content/**`, gateway routes/controllers/tests | Focused gateway Pest; E2E when behavior crosses node/topology boundaries | API/product reviewer when contract changes | Record repeated API contract or routing mistakes | API docs and implementation disagree, or authorization/security impact is unclear |
| Provisioning/live-node | `e2e-verification-lanes`, `implementing-features` | `apps/docs/content/testing/README.md`, provisioning docs, product decisions | Prepared-topology lane, retained topology inspection, then approved live-node proof | Human before live mutation | Always capture topology/node evidence; record expensive or repeated failures | Provider pool/auth is ambiguous, role target is unclear, or live mutation lacks approval |
| Release | `release` | Release skill, changelog/version files, product docs touched by release | Release gates: doctor before, `update:all`, doctor after, `node:list`, plus exception checks | Human before tag, publish, or merge/push beyond the approved release step | Record release-gate surprises and recurring fleet drift | Any release gate fails or approval boundary is not explicit |
| App/package shared core | `implementing-features`, Laravel/PHP skills | `packages/core/**`, affected app docs/tests | Package tests plus focused impacted app tests; broaden to `composer quality-check` for shared contracts | Owner/reviewer for cross-app behavior | Record boundary leaks or repeated shared-contract misses | Affected apps are unknown, or shared behavior lacks targeted coverage |

## Goal Contract

For non-trivial work, fill this contract before implementation. Keep it short
enough to copy into `LOOP.md`.

```markdown
Objective:

Out of scope:

Affected surface:

Stop predicates:

Failure exits:

Evidence required:

Reviewer required:

Human approval boundary:
```

The contract is not ceremony. It defines when the agent should continue, when it
should stop, and which evidence is enough for handoff.

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

The harness surfaces the map; the loop turns session signals into better
guardrails.
