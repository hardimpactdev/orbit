---
name: developing-commands
description: Use when developing or repairing an Orbit command through its documented flow, VM proof, pull request, CI, release and fleet verification.
---

# Develop and deliver an Orbit command

Take one command at a time through the full development and release flow, using an isolated task VM. A command is complete when every step below holds and it has gone through the whole release flow. Record its lessons in the relevant skills before starting the next command.

The target flow, from idea to deployed, is in [the feature flow](../../../docs/reference/feature-flow.md). Write only to `hardimpactdev/orbit`.

For the first complete pass, follow the feature flow's build order: establish the verified proof topology before wiring the proof CI job that resets from it. Reuse the same command cases for development and CI. A manual proof or seed refresh helps build the pipeline, but does not complete its automatic release stages.

## Before code

Three things are in place and agreed before you change code:

1. **Brief and acceptance criteria:** the Task and its group. The criteria decide when you are done.
2. **The docs page of the ideal state:** `docs/cli/<family>/<command>.mdx`: description, usage, how it works, errors. Update it first if it is behind. Use [writing-documentation](../writing-documentation/SKILL.md).
3. **The chart:** `docs/cli/<family>/<command>.mmd`, updated to the ideal flow with [flow-tighten](../flow-tighten/SKILL.md): the main path uses only required parameters, one `opt` block per optional flag, minimal, real steps only.

Call Recall `resolve` for the command and its family first. Current decisions override older instructions.

## The checklist

Work through it in order. Each item ends with evidence in the PR.

1. **Docs match the ideal state.** Review the `.mdx` page, chart and command verification together. The page and chart agree with each other and with the brief. Decide the failure and cleanup contract during this review, using [flow-tighten rule 28](../flow-tighten/SKILL.md#what-a-good-flow-looks-like).
2. **The split is right.**
   - CLI: parse arguments, call the API, show the result. No logic.
   - Gateway: admission (validation, refusals, conflicts), intent rendering, recording the result. No execution.
   - Agent: observe only. Add no agent command; the agent is frozen.
   - Swamp: orchestration, following the chart.
   - Ansible: idempotent Node state with lists, per flow-tighten rule 22. Native Swamp steps for Gateway calls, single commands, work that must not repeat, and budgets under about 10 seconds.
3. **Code is minimal.** Delete unused CLI and Gateway paths for this command, including old execution code and agent command calls it no longer needs. Report net lines.
4. **The workflow matches the chart** step for step; Swamp lane labels equal step names. Build with [swamp-flow](../swamp-flow/SKILL.md) and the phases with [ansible-phase](../ansible-phase/SKILL.md).
5. **Tests.**
   - Use [verifying-commands](../verifying-commands/SKILL.md) to maintain and run the affected proof, including its reset boundary. Update cases for behavior changes; review existing cases for behavior-preserving changes. The same cases and assertions run during development and in CI.
   - Gateway Pest tests only for what this command's Gateway code needs: admission, refusals, intent rendering, projection. The old suite was deleted; for how things were set up, read git history at tag `pest-before-rewrite`. Keep them fast.
6. **Proof.** Run the cases on the shared proof topology. Record fresh and no-drift times against the old workflow.
7. **The whole release flow.** The command is not done until it has gone through all of it:
   1. PR, with auto-merge as `nckrtl` (`gh pr merge <n> -R hardimpactdev/orbit --auto`). This is the interim: the target is that Swamp turns on auto-merge with a GitHub App token that the Gateway mints, on a PR that the Orbit App opened ([PR and CI](../../../docs/reference/command-development-pipeline.mdx)).
   2. CI: fast checks plus the proof CI for this command, selected by the changed files.
   3. Merge through the queue.
   4. Release as a Swamp run.
   5. Per-host refresh: each task VM host refreshes its task image and topology seed.
   6. Fleet: `node:converge` with the release scope (Orbit footprint plus changed roles).
   7. Doctor shows no new drift on the fleet.

   If a step of the release flow is missing, build its minimal form as part of this command. The first command fills these gaps.
8. **Lessons.** Before you finish, add what you learned to this skill (and to swamp-flow, ansible-phase or flow-tighten where it belongs), in the same PR or the next one. Say what changed in the skill in your report.

## Report

End with: the command, the PRs, net lines, fresh and no-drift times, which release-flow steps it went through, the gaps you filled, and the lessons you added here.
