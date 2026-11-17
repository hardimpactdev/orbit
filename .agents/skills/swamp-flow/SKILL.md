---
name: swamp-flow
description: Routes implementation and review of an Orbit command's Swamp workflow. Use for changes under swamp/workflows.
---

# Build a Swamp flow

Start from the command's chart and public page. The [chart README](../../../docs/cli/README.md) owns design rules; [flow-tighten](../flow-tighten/SKILL.md) is a critique checklist. The binding [Swamp shape guide](../../../swamp/docs/swamp-shape.md) alone owns machine execution, placement, secrets, callbacks and result transport. Do not copy grandfathered workflows as templates.

Load the official [swamp skill](../swamp/SKILL.md) for native CLI and workflow mechanics. Use the [porting reference](../../../docs/swamp/porting.md) for Orbit integration and [ansible-phase](../ansible-phase/SKILL.md) for playbooks.

## Implement and check

1. Prepare the chart, page and cases through [preparing-commands](../preparing-commands/SKILL.md). Preserve public edge cases and admission rewrites. Explain phase-order changes in the PR.
2. Change admission, workflow and terminal projection together. Keep Gateway-consumed selectors stable or update their declarations. Check parent and child workflow callers before deleting replaced assets.
3. Build and prove in one [task copy](../../../docs/reference/tasks.md#build-a-feature-in-one-copy), never production serve. Follow [verifying-commands](../verifying-commands/SKILL.md) for nested-fleet receipts and current limits. Repair proofs start from broken or stale state, not setup that makes the write a no-op.
4. Run the changed logic's tests, `bin/check-flows`, `bin/check-operation-kinds` and `bin/swamp-shape-gate` before pushing. The gate owns the pinned tools and credential-dependent validation; report its skips. The operation-kind check covers execution-copy grants that native CEL validation cannot.
5. Follow the [porting review checklist](../../../docs/swamp/porting.md#pr-and-review-checklist) for budgets, proof and deletions, then [developing-commands](../developing-commands/SKILL.md) for independent review, PR and CI. Documentation-only and deletion-only changes need no fleet receipt.

## Current hazards

- Empty fan-out and skipped jobs can move dependents or prevent cleanup. Read the shape guide's native control-flow contract before changing dependency edges.
- Keep dispatch inputs bounded. Pass required statuses, not a whole fleet's `steps` map or history-growing query.
- Manifest assets must reach the worker. A local test reading the checkout does not prove native dispatch delivered them.
- A new intent field requires Gateway admission to supply it before the served workflow reads it. Re-admit pre-release runs rather than resuming them with old input.

Update the existing owner when a rule changes. Put reusable implementation lessons in the relevant reference or `docs/solutions`, not here or in a second copy of the porting guide.
