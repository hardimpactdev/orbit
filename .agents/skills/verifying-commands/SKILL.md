---
name: verifying-commands
description: Defines and runs Orbit command proofs on the nested fleet of a task copy. Use for fleet behavior checks and proof evidence (stages 2 and 3). For terminal rendering alone, use verifying-cli-output.
---

# Prove a command (stages 2 and 3)

This is the entry point for fleet-proof work. Design and run the proofs before opening any PR, following [Build a feature in one copy](../../../docs/reference/tasks.md#build-a-feature-in-one-copy). Tasks use [task copies](../../../docs/reference/tasks.md#task-copy) of a container Instance; proofs act on their nested fleet, never on shared or production machines. The [workspace contract](../../../docs/reference/task-instance-workspace.md) owns checks and export. The stage charts own [the environment](../../../docs/reference/command-development-pipeline.mdx#2-development-environment) and [the proof](../../../docs/reference/command-development-pipeline.mdx#3-proof).

Run `orbit proof FAMILY:COMMAND --group=ID --json` for the task group whose copy you are proving. It tests the current checkout, committed or not. Follow [`orbit proof`](../../../docs/cli/proof/proof.mdx) for prerequisites, refusals, resets and evidence: it resets the nested fleet before each batch and leaves the last batch's state for inspection, not a clean fleet. Build no second runner.

## The nested fleet of a task copy

`nested-gateway` mounts this checkout, `/srv/workspace`, at `/home/orbit/orbit`. Its PHP-FPM, Horizon and scheduler run `/home/orbit/orbit/apps/gateway` as `orbit`. Its `swamp serve` serves `/home/orbit/orbit/swamp` with `--hot-reload`. Run a command there as `sudo incus exec nested-gateway -- runuser -u orbit -- sh -c 'cd /home/orbit/orbit/apps/gateway && …'`.

- A PHP change reaches PHP-FPM within 2 seconds (`opcache.revalidate_freq=2`). Horizon is a long-running process: restart it with `sudo incus exec nested-gateway -- systemctl restart orbit-gateway-horizon`. Swamp's `--hot-reload` reloads extension types and workflow folders on SIGHUP: as `orbit`, run `kill -HUP "$(cat /home/orbit/orbit/swamp/.swamp/serve.pid)"`. `swamp serve reload` needs a Club login that the guest lacks.
- The nested database is `/home/orbit/.orbit/gateway.sqlite`, on the guest's own disk. After a merge of main, its new migrations are `Pending` there: run `php artisan migrate --force`, then restart Horizon.
- The nested Gateway has no release layout: `apps/gateway` is the checkout's folder, `/home/orbit/shared` does not exist, and `gateway:release:list` returns no releases. So a release proof covers only what runs before a release: `swamp/proof/gateway/release-deploy.cases.yaml` checks the local refusal `invalid-commit` and lists the release itself as `uncovered`.
- Each nested container has one snapshot, `base`. Only the fleet reset of `orbit proof` restores it; the `orbit` CLI is not in the copy. Do not restore `base` by hand: the reset play, `swamp/ansible/tasks/instance-fleet-reset.yml`, also clears network pid files, starts the fleet under its rules, migrates and waits for readiness.
- After a proof, remove what you added by hand: probe files in the checkout (the nested Gateway reads them at once), snapshots other than `base`, and nested Instances that no case created. Start what you stopped.

## The cases file

The cases file `swamp/proof/<family>/<command>.cases.yaml` is part of the spec in the first commit of the command's PR ([preparing-commands](../preparing-commands/SKILL.md)). The [format](../../../docs/reference/verification-cases.mdx) and the batches in `swamp/proof/batches.yaml` are the only places that define a case. The shared runner (`swamp/proof/runner/`) runs it, so write no case program.

Review the page, the chart and the cases together. Update cases when behavior changes. For a bug, add a regression case that fails before the fix. Do not weaken an expectation to match a bug.

Each case specifies:

- the starting state that matters, including the Nodes involved;
- the command and inputs, run through the real CLI (a CLI contract needs the CLI built from the tested commit; an API-only proof does not establish CLI behavior);
- the exit status and the public result;
- the Gateway record, the Node state and the real effect to assert;
- what must stay unchanged, including unmanaged resources and state after a refusal;
- what a repeat must do under the documented contract;
- for a failure case, what is cleaned up, restored or retained, per the [chart design rules](../../../docs/cli/README.md#design-rules).

Setup can use other commands, but the case must invoke the command under test through its real interface.

## Write assertions that detect a broken command

- Derive expected results from the documented contract, never from the implementation's reported success. Check the Gateway record, the Node state and the promised effect. Doctor must report no drift for the records the case touched.
- Prove the effect: for a firewall rule, an isolated listener, a control probe, a blocked probe before the command, and an allowed probe after. Arrange traffic so no broader rule admits it. Test both address families when the command promises both.
- Compare state before and after a refusal. For a repeat, assert no extra managed resources and no configuration change. A documented skip must return success and its reason code in human and machine output; prove that partial state is repaired, not reported as already correct.
- Give every refusal guard one case per bypass form: no source, one address, a CIDR that covers the management network.
- Exercise failures after meaningful side effects. Verify that cleanup keeps pre-existing resources and that retained targets stay inspectable.
- Wait for readiness within a bounded deadline. Report infrastructure failures separately from failed assertions; neither is a pass.
- Demonstrate that a regression case detects the intended failure and record the before/after evidence. Keep `fails_on_base` metadata where appropriate, but do not count it as executed proof: [copy proofs do not replay flagged cases on the merge base yet](../../../docs/cli/proof/run.mdx#the-run). Replay is unavailable until ported to copies. Task command deliverables have a separate [start-commit check](../../../docs/reference/tasks.md#prove-a-command-fails-on-the-start-commit); that is not proof-run merge-base replay.

Cases have no undo. Each uses its own resources. A case that changes shared state (firewall off, an injected failure, a held lock) gets its own batch.

Keep each protocol's bound firewall identity and select the placement anchor for the same protocol. Batch cases can reuse fixture names and leave earlier rules behind, so a comment alone can select another case's rule. Probe a partial state before the action to prove the line that should stay already works.

Derive Operation assertions and locks from every actual CLI call's write kind and target, not the file's command or placed Node alone. Scope known writes before the API selects the newest page. Cross-Node calls, unresolved targets and commands without a known write scope (including reads) run exclusively and audit without filters. A no-request case shares its Node only when its setup, stages and negative steps are all reads; a fault, a held lock or a service step is a writer on that Node. Package checks are reads; `node_fixture` and `recorded_request` keep the fleet exclusive. Prepare every required `cli.as` caller once before scheduling, so checkout, CLI home, CA and profile writes cannot overlap readers or writers. Test fresh callers shared by readers, caller preparation beside a writer, reader overlap, writer exclusion in both orders, and unexpected writes from read commands.

## CI

CI proof is intentionally off for now. Do not enable it or wait for it to discover failures: the agent's pre-PR proof is the evidence; a later CI proof should be a formality that always passes. [`proof:run`](../../../docs/cli/proof/run.mdx) owns the switch, selection and verdict contracts. Its invocation is `orbit proof:run COMMIT [--base=SHA | --all] --json`, without `--group`; it creates disposable copies of its own. Use [the selection mapping](../../../docs/reference/feature-flow.md#choose-the-cases) to cover the changed behavior before the PR. A skipped CI proof is not evidence that cases ran.

## Report

State the commit, the cases selected and run, the verdicts, the functional assertions and the Doctor result. Name unsupported cases and harness limits.

The runner owns case expansion and comparison. CI publishes its exact base/head result for Gateway admission. Never add a YAML comparator in another language; missing or invalid selection results run whole files.

Keep the tested Operation's public failed step and error code in failed CLI checks, including setup failures; never infer the failed phase from a skipped end check. Follow [`orbit proof` verdicts](../../../docs/cli/proof/proof.mdx#verdicts) for task-copy evidence and [`proof:run` cleanup](../../../docs/cli/proof/run.mdx#after-the-run) for disposable runs. `proof:run` destroys copies on every run end, failures included, and rejects `keep_failed`; preserve sanitized diagnostics before they disappear. Its verdicts stay in the result, but a durable receipt store is not built yet. Failed, unrun, interrupted or unavailable proof is not a pass.

A disposable Gateway needs a revision and an installable CLI release even though a PR has no published release. Prepare its tested revision and the existing test-only static catalog before cases. Keep the CLI under test on candidate source, and pin the separate installation fixture to a real published binary and checksum.

Runner, selection code and selection-list changes disable narrowing for commands selected by the same diff. Tooling changes alone still select nothing. Keep this guard in the shared runner result.

Exercise a case's JSON selectors against the shared runner before running the fleet. A rejected selector does not prove a missing record. Test native-output readers against complete recorded artifacts, and report safe exception types and locations when a guest helper fails; never print raw output or exception arguments.

Match fixtures to the native behavior and transport contracts: `command/shell` writes structured stdout in `result` and plain text in `log`. Resolve the recorded `result` data ID and version. A native WebSocket `data.query` with a map `select` returns values in `projected.rows` with an empty `results`, unlike the flattened CLI JSON. Capture the actual transport envelope before mocking it. An invented artifact name or response shape can make a mock test pass while the real reader fails.

For a proof in a task copy, check the caller’s checkout ID map and Unix groups together. An unshifted mount can still be unreadable when `incus exec --user` omits supplementary groups. Use the fleet rules for the mount and `runuser` for the managed identity; see [the proof run](../../../docs/cli/proof/proof.mdx#the-run).
