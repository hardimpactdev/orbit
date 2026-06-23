# Orbit Repo Harness

Orbit is an LLM-first monorepo. The repository harness lives at the monorepo
root. It is the durable surface agents use to discover how to work on this
codebase.

## Scope

This harness governs **repo development only**: how agents plan, implement,
verify, and hand off changes to the Orbit repository. It does not define
customer/product runtime behavior, fleet operations, or how Orbit helps agents
operate customer workspaces. Product contracts live under
`apps/docs/content/`.

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
- Automation loop (nightly distillation, signal mining)
- Reviewer-persona framework

`LOOP.md` and `HARNESS_SIGNALS.md` define the manual feedback-loop layer. Later
slices may add reviewer personas, automation, and `evalc/` only after the manual
loop is stable.

## Agent Discovery Path

Start at the monorepo root and read in this order:

1. **`AGENTS.md`**: repo shape, authority chain, verification commands,
   worktree workflow
2. **`HARNESS.md`**: this file; repo harness anchor
3. **`LOOP.md`**: how implementation signals become durable guardrails
4. **`HARNESS_SIGNALS.md`**: signal-to-guardrail-target map for the feedback loop
5. **`.agents/skills/`**: domain procedures activated just-in-time per change
   type
6. **`apps/docs/content/`**: product authority (behavior contracts, not
   repo-dev procedures)
7. **`bin/orbit-prepare-worktree`**: create and bootstrap isolated
   implementation worktrees
8. **Root Composer scripts**: orchestrate docs-lint, tests, Pint, PHPStan,
   Rector, and E2E lanes across apps/packages

Session plans and specs stay at `docs/superpowers/`. They are not product
authority and are not the durable harness.

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
