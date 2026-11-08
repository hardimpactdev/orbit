---
name: verifying-commands
description: Use when defining or running the proof cases of an Orbit command on the nested fleet of a task VM (stages 2 and 3). For terminal rendering alone, use verifying-cli-output.
---

# Prove a command (stages 2 and 3)

The flows are the stage charts: [2, the proof environment](../../../docs/reference/command-development-pipeline.mdx#2-development-environment) and [3, the proof](../../../docs/reference/command-development-pipeline.mdx#3-proof). In the task VM, run the CLI and direct commands against the nested fleet, which mounts your checkout. To run the cases of a command, run `orbit proof FAMILY:COMMAND` there: it restores `base`, runs the cases and leaves a clean fleet on your code ([`orbit proof`](../../../docs/cli/proof/proof.mdx)). The same cases and the same runner run in CI ([stage 4](../../../docs/reference/pipeline/4-pr-and-ci.mmd)). Build no second runner.

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
- for a failure case, what is cleaned up, restored or retained, per [flow-tighten rule 28](../flow-tighten/SKILL.md#what-a-good-flow-looks-like).

Setup can use other commands, but the case must invoke the command under test through its real interface.

## Write assertions that detect a broken command

- Derive expected results from the documented contract, never from the implementation's reported success. Check the Gateway record, the Node state and the promised effect. Doctor must report no drift for the records the case touched.
- Prove the effect: for a firewall rule, an isolated listener, a control probe, a blocked probe before the command, and an allowed probe after. Arrange traffic so no broader rule admits it. Test both address families when the command promises both.
- Compare state before and after a refusal. For a repeat, assert no extra managed resources and no configuration change. A documented skip must return success and its reason code in human and machine output; prove that partial state is repaired, not reported as already correct.
- Give every refusal guard one case per bypass form: no source, one address, a CIDR that covers the management network.
- Exercise failures after meaningful side effects. Verify that cleanup keeps pre-existing resources and that retained targets stay inspectable.
- Wait for readiness within a bounded deadline. Report infrastructure failures separately from failed assertions; neither is a pass.
- Prove a new case can fail: the proof CI does it on its proof VM, after the main verdicts, on the merge base with only your cases file; every new case must fail there. Never change your own checkout to try it. Built, not yet passed live (stage 3 chart).

Cases have no undo. Each uses its own resources. A case that changes shared state (firewall off, an injected failure, a held lock) gets its own batch.

Keep each protocol's bound firewall identity and select the placement anchor for the same protocol. Batch cases can reuse fixture names and leave earlier rules behind, so a comment alone can select another case's rule. Probe a partial state before the action to prove the line that should stay already works.

Scope Operation assertions to the command's kind and target before the API's newest-50 limit. A no-request case shares its Node only when its setup, stages and negative steps are all reads; a fault or a held lock is a writer on that Node. Test reader overlap and writer exclusion in both orders when changing scheduling.

## CI

Run the cases selected by `swamp/proof/selection.txt` and definition comparison ([choose the cases](../../../docs/reference/feature-flow.md#choose-the-cases)). Command code selects the whole file. Cases-only changes select changed or added entries and users of changed fixtures, vars or batch setup. Shared code selects nothing by itself. Documentation alone never starts the proof CI. A missing result or an unrun selected case fails the run.

## Report

State the commit, the cases selected and run, the verdicts, the functional assertions and the Doctor result. Name unsupported cases and harness limits.

The runner owns case expansion and comparison. CI publishes its exact base/head result for Gateway admission. Never add a YAML comparator in another language; missing or invalid selection results run whole files.

Keep the tested Operation's public failed step and error code in failed CLI checks, including setup failures. CI deletes full receipts with the VM, so a step-count mismatch alone loses the cause. Rerun with failed-VM retention when the existing verdict lacks it; never infer the failed phase from a skipped end check.

A disposable Gateway needs a revision and an installable CLI release even though a PR has no published release. Prepare its tested revision and the existing test-only static catalog before cases. Keep the CLI under test on candidate source, and pin the separate installation fixture to a real published binary and checksum.

Runner, selection code and selection-list changes disable narrowing for commands selected by the same diff. Tooling changes alone still select nothing. Keep this guard in the shared runner result.

Exercise a case's JSON selectors against the shared runner before running the fleet. A rejected selector does not prove a missing record. Test native-output readers against complete recorded artifacts, and report safe exception types and locations when a guest helper fails; never print raw output or exception arguments.

Match fixtures to the native behavior and transport contracts: `command/shell` writes structured stdout in `result` and plain text in `log`. Resolve the recorded `result` data ID and version. A native WebSocket `data.query` with a map `select` returns values in `projected.rows` with an empty `results`, unlike the flattened CLI JSON. Capture the actual transport envelope before mocking it. An invented artifact name or response shape can make a mock test pass while the real reader fails.
