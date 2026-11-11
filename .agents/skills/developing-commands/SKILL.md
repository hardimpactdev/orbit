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

When a deployment captures source metadata, test an older pinned commit after the branch advances. A branch-head fake alone cannot prove that the selected runtime and the checked-out commit agree. Check recorded lifecycle refusals before reading remote metadata. Read it before opening any admission write transaction, including an automatic caller’s outer transaction; recheck lifecycle, source settings and idempotency under ownership before recording the result.

Task parents that can use a ready VM capture resume only for a parked VM. Keep `task_vm_id` separate from the optional resume intent, and let the next workflow step follow either a succeeded or skipped resume.

For a command namespace change, audit model route bindings and operation fingerprints. A selector may span rows hidden by a global scope; public names must not change stored operation or retry identity.

When parallelizing CI commands, bound their nested worker pools as well as the outer executor. Keep prerequisite setup and ratchets ordered, and give each command its own output and temporary directory.

Before you finish, add what you learned to the skill that owns it (this one, swamp-flow, ansible-phase or flow-tighten), in the same PR. Say what changed.

For a shape-gate cancellation, identify the slow command from its completed output. Long fixture files share the existing worker budget only after they are split into independent files.


A provider retirement needs its full resource inventory. An empty task VM sweep does not prove templates or storage are absent; verified templates can be deliberately excluded from sweep results. Close that inventory before removing the provider path.

Receipt redaction tests must inspect the persisted response. A numeric mask can also occur legitimately in an identity, digest or timestamp; searching the whole receipt creates clock-dependent false failures.

When updating CLI fixtures from resource replies to Operations, preserve product result assertions as well as the transport envelope. Use the complete terminal result from its writer and an independently specified exact expectation. Check human warnings against the current command chart; a generic Operation success line does not prove them.

Run a combined Unit and Feature test collection when adding global test constants or helpers. Separate focused runs do not catch duplicate names across files.

Before changing a CI cache, separate hits from misses in job logs. A Cargo lock change can restore dependencies built by the same compiler on the same OS and architecture; Cargo fingerprints still decide what must rebuild.

Nested command proof must cross the same CLI, typed SDK and Gateway validation boundaries as ordinary commands. Reuse CI's cases-file lookup so `node:role:add` resolves to `node/role-add.cases.yaml`. Run the approved human-only refusal cases too: JSON coverage alone does not prove that operators can see the stable error code. Pass that code through the shared renderer's code argument, not through its bounded validation fields. Map the command's implementation in the shared proof selection list when its cases become runnable.


Ansible fixture deadlines need pinned-Ansible and orphan regressions: test an exited leader and a child forked during TERM. Own descendants from launch with a Linux subreaper, signal through pidfds, and bound output collection. Deno test files have separate isolates: establish a shared command deadline in the parent environment and test exhaustion across two files. Size both the playbook guard and the shared serial-command budget from slow-runner measurements; include a stall in the last playbook and the rest of the gate in the arithmetic.

## Report

The command, the PR, net lines, the cases run and their verdicts, the gaps you filled, and the lessons you added.



Each host has separate `task_places` and `ci_places`; one VM holds one place until confirmed deletion. Task publication releases the VM. Later build, fix and review work claims again from the base and task branch. Full pools wait within a two-hour budget; authenticated CI eligibility comes from the caller Node, never reporting flags.

CI capacity is a bounded wait, never a passing no-case outcome. A proof report must account for every admitted case and every selected command. Read full Swamp output artifacts for merged reports; the native history summary keeps only 4 KiB.

Released proof workflows run the runner from each PR head. Check its help for support before calling a new runner command; an older open PR can still use the previous interface.

A PTY key driver must acknowledge consumed input, not rendered output. With color off, moving a selector highlight can produce no new bytes. Wait for the complete key to enter the stopped reader’s terminal queue, then for consumption before sending the next key. Start on a marker unique to the prompt, not a progress label.

After adding an API input, regenerate OpenAPI and the MCP manifest and exercise the generated tool through admission. Ignored generated outputs are release artifacts; source support alone does not prove the deployed tool exposes the input.

A migrated Instance has no clone source. Test lifecycle consumers against migrated non-defaults too; identify the default by its name, and retire migration execution copies during destroy.

Large SQLite fixtures need bounded insert batches. A single insert can exceed the build's bound-parameter limit; formatting its huge QueryException may consume the test deadline and hide the actual error. Keep the production row count and indexes, then measure compilation, preparation and execution before changing a timeout.

Inspect the whole previous-exception chain when a timed test reports a migration error. Laravel can wrap PHPUnit's timer exception in a QueryException, and an action can wrap it again. Account for work before lazy database initialization; use fixed test inputs for RSA prime search while preserving the real signing and database paths.

A Process preset moved into a container needs the shared container ingress and Route projection as well as its unit. Keep its assigned port in the shared intent so deployment rebuilds preserve access, and use container controls after cutover instead of the Node preset preparation.

Gateway releases admit refresh jobs only after verification and the new Horizon start. A command from a new release can serialize a job class that old workers cannot load. Keep the pre-load Operation guard separate from the Horizon drain: Swamp runs can outlive the queue job that started them.

For an attachment read, reuse the operation's ordered inventory and eager-load its identity graph. Project public fields explicitly, and compare query counts with one attachment and several on distinct Nodes.

Native terminal eligibility belongs in the shared recorder under the Operation lock. If private reads permit cancellation confirmation to arrive before family writes commit, throw from that recorder to roll back the family transaction, then recheck and write the generic receipt in a short transaction. A provisional expiry does not override a verified in-budget completion; reopen it.
