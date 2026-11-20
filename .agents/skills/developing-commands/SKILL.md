---
name: developing-commands
description: Builds and proves an Orbit command or feature before opening its complete PR. Use after its spec exists, through CI and the authorized delivery gate.
---

# Develop a command or feature

Read the current stage's chart in the [command development pipeline](../../../docs/reference/command-development-pipeline.mdx); charts win over other text. For a command, [prepare the spec](../preparing-commands/SKILL.md) as the first branch commit before coding. Keep the implementation and proof cases on that branch. Open no PR, draft or ready, until the whole feature works and its end-to-end proof passes.

## Build and prove

Use one [task copy](../../../docs/reference/tasks.md#task-copy) of a container Instance and its nested fleet for fleet behavior. Follow [Build a feature in one copy](../../../docs/reference/tasks.md#build-a-feature-in-one-copy): exercise the real result, codify manual changes and fix the next layer there, not through repeated releases.

Use the owner for the part you change:

- [Architecture](../../../docs/architecture.md) and the command chart for component boundaries; the CLI calls the API, the Gateway admits intent and records results, and Swamp executes. The agent remains an observer.
- [Swamp shape guide](../../../swamp/docs/swamp-shape.md), [swamp-flow](../swamp-flow/SKILL.md) and [ansible-phase](../ansible-phase/SKILL.md) for execution.
- [Contributor guide](../../../docs/contributor-guide.md) and the changed project's `AGENTS.md` for tests, checks, generated contracts and documentation impact. In a task, run targeted tests; the Gateway owns the [scoped task check](../../../docs/reference/tasks.md#scoped-task-check), not a repeated full `composer check` or `bin/review-check` in each turn.
- [verifying-commands](../verifying-commands/SKILL.md), [`orbit proof`](../../../docs/cli/proof/proof.mdx) and [Verification cases](../../../docs/reference/verification-cases.mdx) for the end-to-end proof. A plan, a mapping or a refusal-only run is not evidence that the successful path works.
- [verifying-cli-output](../verifying-cli-output/SKILL.md) for terminal behavior and [verifying-web-ui](../verifying-web-ui/SKILL.md) for web layout.

For a non-command feature, settle its contract with [grill-with-docs](../grill-with-docs/SKILL.md) and write the owning documentation before implementation. Put lasting lessons in the existing reference or `docs/solutions`, not an incident log in this skill.

## Deliver only the proven result

CI proof confirms an already-passing local proof. It is deliberately disabled by `ORBIT_PROOF_ENABLED` until commands have been checked systematically; do not enable it as part of ordinary feature work. Copy proofs do not replay flagged cases on the merge base. The [pipeline](../../../docs/reference/command-development-pipeline.mdx#4-pr-and-ci) owns both limits. Neither a skipped CI proof nor green fast checks replace the pre-PR proof. [Stage 5](../../../docs/reference/pipeline/5-automatic-release.mmd) and [stage 6](../../../docs/reference/pipeline/6-task-image-seed-refresh.mmd) own release and seed refresh; do not trigger a release to discover whether the feature works.

When proof depends on acceptance before completion, bind both replies to the owning Node before queueing. Use one ordered list and exercise both lines already queued, completion alone, and acceptance from another Node.

### In an Orbit task

A task implementer pushes nothing, opens no PR and queues nothing. It commits, runs the task check, and hands off to Orbit's reviewer and publisher, as [orbit-tasks](../orbit-tasks/SKILL.md) and the turn prompt say. The steps under "Outside a task" are not its own.

### Outside a task

Follow [stage 4](../../../docs/reference/pipeline/4-pr-and-ci.mmd) for independent review, one complete PR, CI and the authorized merge gate. Before a push, run `bin/docs-impact --gate --base origin/main`; the contributor guide owns proposed PR-body waivers.

Watch CI, fix failures caused by the change and repeat affected proofs after fixes. Report unrelated failures separately. Publication is not merge or release approval. After the gate, the author queues the pull request; the [merge queue](../../../docs/reference/implementation-loop.md#merge-queue) runs the fixture shards and merges it.

Report the PR, tested checkout, cases and verdicts, checks, unresolved coverage and actual delivery state.

Cache interpreter-independent Ansible collections separately from lint virtual environments. Size the CI job for a cold collection install plus lint and cache publication; a timeout before cache save makes the next run cold again.
