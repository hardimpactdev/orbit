---
name: preparing-commands
description: Prepares an Orbit command's chart, page and proof cases as the first branch commit before implementation. Use for stage 1 of the command development pipeline.
---

# Prepare a command

Read the [stage 1 chart](../../../docs/reference/pipeline/1-prep.mmd) first. The [pipeline](../../../docs/reference/command-development-pipeline.mdx) owns the order: spec first, then build and passing end-to-end proof in one task copy with its nested fleet, then one complete PR. Preparation opens no PR, draft or ready.

## Set the contract

Read the brief, the owning reference or ADR, the command's existing chart and page, and current decisions. Read the code to find implementation gaps, not to redefine the target. For a sibling command, use its reviewed chart as a model, but decide the outcomes that differ; a draft sibling is not authority.

State a short Does / Does not boundary. Settle inputs, defaults, effects, refusals, repeats and failure recovery before drafting. Ask the owner about product choices that the brief and owning rules do not settle.

## Write the spec

Keep the spec as the first commit on the feature branch, with no implementation code:

- `docs/cli/<family>/<command>.mmd`: follow [flow-tighten](../flow-tighten/SKILL.md) and the [chart rules](../../../docs/cli/README.md). Chart the intended flow first, including alternate outcomes and failure recovery. Read the [Swamp shape guide](../../../swamp/docs/swamp-shape.md) before specifying execution.
- `docs/cli/<family>/<command>.mdx`: follow [writing-documentation](../writing-documentation/SKILL.md), [designing-cli-commands](../designing-cli-commands/SKILL.md) and the [CLI standard](../../../docs/reference/cli-ux.md). Keep usage, flags, errors and repeat behavior consistent with the chart; link shared rules instead of copying them.
- `swamp/proof/<family>/<command>.cases.yaml`: follow [Verification cases](../../../docs/reference/verification-cases.mdx). Derive cases from the chart and page. Use one cases file per command, current runner syntax and declared batches. Check effects and unchanged state, not only exit zero or an Operation status.

Budget one case per distinct chart outcome, plus a repeat and required refusals, counting each outcome once. Include recovery from partial changes where possible. Combine compatible assertions instead of multiplying cases by option values or generic invalid inputs. Preserve existing runnable case IDs and batches unless the chart removes their behavior; explain removals or renames. Shared-state changes need a restored batch or one staged exclusive case, not serialization alone.

Mark runnable failures as `fails_today`. Record a missing runner capability under `uncovered`, never as an invented step; it becomes development work, not permission to claim coverage. Keep `fails_on_base` metadata only for new or fixed behavior that should fail by assertion on the base. Copy proofs do not execute merge-base replay; the [pipeline's proof section](../../../docs/reference/command-development-pipeline.mdx#3-proof) owns this limitation.

## Validate and hand off to development

Run from the repository root:

```bash
node bin/check-flows
composer docs-build
composer docs-lint
python3 swamp/proof/runner/runner.py plan --file swamp/proof/FAMILY/COMMAND.cases.yaml
python3 -m unittest discover -s swamp/proof/runner/tests
```

The plan must accept the file. A plan proves only that definitions load, not that the command works. Report runner gaps and failing tests rather than weakening cases or changing tests to fit the spec. Resolve required coverage gaps during development before opening the PR.

Have a fresh independent read-only agent review the spec against its owning contracts. Fix findings and repeat affected checks. Leave changed charts at `%% status: draft` until the owner approves them; the author does not mark them reviewed.

Commit the spec first and continue on the same branch with [developing-commands](../developing-commands/SKILL.md). Report the scope boundary, planned and expanded case counts, runnable failures, uncovered capabilities, changed case IDs and validation results. Do not open a spec-only PR.
