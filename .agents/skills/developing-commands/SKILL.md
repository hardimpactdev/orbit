---
name: developing-commands
description: Use when developing or repairing an Orbit command or feature after its spec exists. Covers the code, the proof, and the PR through CI, merge and release (stages 2 to 6).
---

# Develop a command or feature (stages 2 to 6)

The flow is in the [command development pipeline](../../../docs/reference/command-development-pipeline.mdx) and its stage charts in `docs/reference/pipeline/`. Read the chart of your stage before you act; the charts win over any other text. Write only to `hardimpactdev/orbit`.

A command change has **one PR**. Its first commit is the spec ([preparing-commands](../preparing-commands/SKILL.md)). You add the code and the runnable cases to the same branch. Open no second PR.

## Before code

In the first commit of the draft PR, or already approved there:

1. The Task and its acceptance criteria. They decide when you are done.
2. The docs page, the chart and the cases file of the ideal state. Call Recall `resolve` for the command and its family.

For a feature that is not a command, follow the [contributor guide](../../../docs/contributor-guide.md): settle the behavior with [grill-with-docs](../grill-with-docs/SKILL.md), write the ADR and docs first, then the code and tests. Use [designing-cli-commands](../designing-cli-commands/SKILL.md) for CLI changes, [verifying-cli-output](../verifying-cli-output/SKILL.md) for terminal rendering and [verifying-web-ui](../verifying-web-ui/SKILL.md) for the web app.

## Build

1. **The split is right.**
   - CLI: parse arguments, call the API, show the result. No logic.
   - Gateway: admission (validation, refusals, conflicts), intent, recording the result. No execution.
   - Agent: observes only. Add no agent command; `apps/agent` is frozen.
   - Swamp: all execution, following the chart. Build it with [swamp-flow](../swamp-flow/SKILL.md) and, for Node state, [ansible-phase](../ansible-phase/SKILL.md).
2. **The code is minimal.** Delete the old CLI and Gateway paths of this command, including execution code it no longer needs. Report net lines.
3. **The workflow matches the chart** step for step; Swamp lane labels equal step names.
4. **Tests.** Gateway Pest tests only for what this command's Gateway code needs: admission, refusals, intent, projection. Run the affected proof with [verifying-commands](../verifying-commands/SKILL.md). The runnable cases go in the same PR as the code.

## PR, CI, merge

Stage 4 is the [chart](../../../docs/reference/pipeline/4-pr-and-ci.mmd). Push to the branch of the draft PR. CI runs on every push. When it is green on the head, take the PR out of draft. After the gate, the PR is squash-merged into `main`. There is no merge queue and no GitHub auto-merge. Until Swamp merges, the author squash-merges the green PR as `nckrtl` with `gh pr merge <n> -R hardimpactdev/orbit --squash`. Use `timeout` on every `gh` call.

Whoever turns `main` red fixes it forward, first. Watch CI on `main` after every merge. Before every push, run `bin/docs-impact --gate --base origin/main`.

## Release

Nothing to do. The release ([stage 5](../../../docs/reference/pipeline/5-automatic-release.mmd)) and the per-host refresh ([stage 6](../../../docs/reference/pipeline/6-task-image-seed-refresh.mmd)) are automatic. If a step of the pipeline is missing, build its smallest form as part of the command that needs it.

## Lessons

For a command namespace change, audit model route bindings and operation fingerprints. A selector may span rows hidden by a global scope; public names must not change stored operation or retry identity.

When parallelizing CI commands, bound their nested worker pools as well as the outer executor. Keep prerequisite setup and ratchets ordered, and give each command its own output and temporary directory.

Before you finish, add what you learned to the skill that owns it (this one, swamp-flow, ansible-phase or flow-tighten), in the same PR. Say what changed.

For CI proof reports, distinguish admission capacity from assertions. Classify only explicit capacity refusals with no case rows and a known selection. Preserve a receipt and skip the assertion check; never label an unrun proof as passed.

A provider retirement needs its full resource inventory. An empty task VM sweep does not prove templates or storage are absent; verified templates can be deliberately excluded from sweep results. Close that inventory before removing the provider path.

Receipt redaction tests must inspect the persisted response. A numeric mask can also occur legitimately in an identity, digest or timestamp; searching the whole receipt creates clock-dependent false failures.

Run a combined Unit and Feature test collection when adding global test constants or helpers. Separate focused runs do not catch duplicate names across files.

## Report

The command, the PR, net lines, the cases run and their verdicts, the gaps you filled, and the lessons you added.
