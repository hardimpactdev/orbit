---
name: command-pristine
description: Use when taking one Orbit command through the per-command loop until it is pristine: docs, chart, CLI/Gateway/Swamp/Ansible split, minimal code, tests, and the full release flow. One command at a time.
---

# Make one command pristine

Orbit converts its commands one at a time, each in its own task VM. A command is pristine when every step below holds and it has gone through the whole release flow. The next command starts only after the lessons from this one are in this skill.

The target flow, from idea to deployed, is in [the feature flow](../../../docs/reference/feature-flow.md). Write only to `hardimpactdev/orbit`.

## Before code

Three things are in place and agreed before you change code:

1. **Brief and acceptance criteria:** the Task and its group. The criteria decide when you are done.
2. **The docs page of the ideal state:** `docs/cli/<family>/<command>.mdx`: description, usage, how it works, errors. Update it first if it is behind. Use [writing-documentation](../writing-documentation/SKILL.md).
3. **The chart:** `docs/cli/<family>/<command>.mmd`, updated to the ideal flow with [flow-tighten](../flow-tighten/SKILL.md): the main path uses only required parameters, one `opt` block per optional flag, minimal, real steps only.

Call Recall `resolve` for the command and its family first. Current decisions override older instructions.

## The checklist

Work through it in order. Each item ends with evidence in the PR.

1. **Docs match the ideal state.** The `.mdx` page and the chart agree with each other and with the brief.
2. **The split is right.**
   - CLI: parse arguments, call the API, show the result. No logic.
   - Gateway: admission (validation, refusals, conflicts), intent rendering, recording the result. No execution.
   - Agent: observe only. Add no agent command; the agent is frozen.
   - Swamp: orchestration, following the chart.
   - Ansible: idempotent Node state with lists, per flow-tighten rule 22. Native Swamp steps for Gateway calls, single commands, work that must not repeat, and budgets under about 10 seconds.
3. **Code is minimal.** Delete unused CLI and Gateway paths for this command, including old execution code and agent command calls it no longer needs. Report net lines.
4. **The workflow matches the chart** step for step; Swamp lane labels equal step names. Build with [swamp-flow](../swamp-flow/SKILL.md) and the phases with [ansible-phase](../ansible-phase/SKILL.md).
5. **Tests.**
   - VM cases from the chart: the main path, one per optional flag, one per error path. Each asserts Doctor reports zero drift for the records it touched and that a second run changes nothing. An error case asserts the refusal and no change.
   - Gateway Pest tests only for what this command's Gateway code needs: admission, refusals, intent rendering, projection. The old suite was deleted; for how things were set up, read git history at tag `pest-before-rewrite`. Keep them fast.
6. **Proof.** Run the cases on the shared proof topology. Record fresh and no-drift times against the old workflow.
7. **The whole release flow.** The command is not done until it has gone through all of it:
   1. PR, with auto-merge as `nckrtl` (`gh pr merge <n> -R hardimpactdev/orbit --auto`).
   2. CI: fast checks plus the VM job for this command, selected by the changed files.
   3. Merge through the queue.
   4. Release as a Swamp run.
   5. Template refresh: converge onto the templates, snapshot, copy to the task VM hosts.
   6. Fleet: `node:converge` with the release scope (Orbit footprint plus changed roles).
   7. Doctor shows no new drift on the fleet.

   If a step of the release flow is missing, build its minimal form as part of this command. The first command fills these gaps.
8. **Lessons.** Before you finish, add what you learned to this skill (and to swamp-flow, ansible-phase or flow-tighten where it belongs), in the same PR or the next one. Say what changed in the skill in your report.

## Report

End with: the command, the PRs, net lines, fresh and no-drift times, which release-flow steps it went through, the gaps you filled, and the lessons you added here.

## Lessons from the first Ansible conversion

- When a phase replaces several native steps, update the Gateway projections that read their names. Test a successful phase followed by a later failure: completed publication stays recorded.
- Prove private-input cleanup after a failure that happens after vault staging. An admission refusal never creates those files, so it cannot prove cleanup.
- A new intent field needs its Gateway producer in an earlier release. Confirm the live release SHA before shipping the workflow reader; a merged producer alone is not release uptake.
- Prepare every Node a command touches with its baseline state and a working observer. A prior case must not supply missing prerequisites by accident. Keep proof-network allowances separate from product firewall policy.
