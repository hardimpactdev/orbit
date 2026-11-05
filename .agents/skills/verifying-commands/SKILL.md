---
name: verifying-commands
description: Use when defining or running deterministic VM proofs for an Orbit command, including the cases shared by local development and CI. For terminal rendering alone, use verifying-cli-output.
---

# Verify an Orbit command on a VM topology

Prove the command's documented behavior with executable assertions on isolated real Nodes. A successful Operation or matching configuration file is not sufficient when the command promises an observable effect.

Use this with [developing-commands](../developing-commands/SKILL.md). The target CI and template lifecycle is in [feature-flow](../../../docs/reference/feature-flow.md). Existing tooling starts at [the proof harness](../../../swamp/proof/README.md). Extend that harness instead of building a second local or CI runner.

## Define the cases

A command change has one PR. The cases file (`swamp/proof/<family>/<command>.cases.yaml`, [format](../../../docs/reference/verification-cases.mdx)) is part of the spec in the first commit of the draft PR, with the chart and the page, and is reviewed there. The shared runner (`swamp/proof/runner/`) runs it, so write no case program. The older hand-written programs (`swamp/proof/<family>/cases/*.sh`) and tables (`<command>.cases.md`) are an old path: convert, do not extend ([old paths](../../../docs/reference/old-paths.md)). Proof CI runs on every push. Do not open a separate docs-only PR for cases.

Review the command's page, chart and verification together. Cover the call with required arguments only, each optional flag, error paths and interactions that can change the outcome. Update cases when behavior changes; for behavior-preserving changes, review the existing cases without a mandatory file edit. For a bug, add a regression that fails before the fix. Do not weaken an expectation to match a bug.

Keep verification assets version-controlled with the command in `swamp/proof/`: the cases file `swamp/proof/<family>/<command>.cases.yaml` and the batches in `swamp/proof/batches.yaml`. The old entry fixtures `swamp/proof/<family>.<command>.json` cannot run any more; read them to port a kind. Keep native verification workflows alongside those assets and stage and load them into the isolated proof controller. Do not improvise them at run time or install them in production for verification. Share reset and workflow phases when parameters suffice. Once an executable verification entrypoint exists, link to it from the command page and chart; do not add a dead link.

Native Swamp workflows own the verification sequence, setup, checks and cleanup; use existing bounded helpers where needed. Do not add Ansible as a dependency of the current verification pipeline. Ansible remains available for product operation flows, and its use in verification may be reconsidered later.

Start each independent command-verification run with a fresh nested topology. Reuse it across cases, cleaning up after each case. Reset the nested topology again for an explicit clean case or after failed cleanup. Preserve the outer task VM, Pi and checkout.

Each case specifies:

- The initial recorded and observed state, including the relevant topology participants.
- The command and inputs, expected exit status and public result.
- The recorded state, Node state and functional effect to assert after completion.
- What must remain unchanged, including unmanaged resources and state after a refusal.
- What repeating the request must do under the command's documented contract.
- For failure cases, what is cleaned up, restored or retained, and how retry or removal settles retained state, following [flow-tighten rule 28](../flow-tighten/SKILL.md#what-a-good-flow-looks-like).

Setup can use other commands or shared fixtures, but the case must invoke the command under test through its real interface. A CLI contract needs the built CLI from the tested commit; an API-only proof does not establish CLI behavior.

## Write assertions that detect a broken command

Derive expected results from the documented contract, independently of the implementation's reported success. Check the Gateway record, the observed Node state and the promised effect. Doctor must report no drift for the records the case touched.

For `firewall:allow`, use an isolated listener and probe Nodes. Confirm the listener is healthy through a control probe, then establish that the tested port is blocked over the test path before the command. After the command, the allowed source must reach it; a source outside a restricted rule must still be blocked. Check the protocol, range, label and rule order where the case exercises them. Test both address families when the command promises both.

The current minimal topology has a broad unmanaged `proof-network` allowance. A probe admitted by that rule cannot prove the new rule works. Arrange the case's test traffic so no broader rule admits it, without removing management access or changing another case's shared topology.

Compare relevant state before and after a refusal. For a repeat, assert no additional managed resources or configuration changes. A documented successful skip must return success and its reason code in human and machine output; also prove that partial state is repaired rather than reported as already correct. New Operation history may be expected; do not compare the entire database byte for byte. Distinguish a documented duplicate refusal from replay with the same idempotency key. Neither should create duplicate Node state.

Give every refusal guard one case per bypass form, not only the obvious input. For a protected port, deny it with no source, with a single address and with a CIDR that covers the management network.

Exercise failures after meaningful side effects, including partial changes within a step. Verify cleanup preserves pre-existing resources, retained targets remain inspectable, and repeated recovery leaves no duplicates or disposable leftovers. Where interruption or cleanup failure can change the result, prove that incomplete cleanup stays visible and can be completed safely. Do not count an ordinary failure test as proof of crash recovery.

Wait for observable readiness within a bounded deadline. Report infrastructure failures separately from failed command assertions, but neither is a passing case. Include setup, failed assertions and cleanup in the receipt.

Prove the test can fail. For example, on an isolated checkout remove the mutation and confirm the functional assertion fails, or use the real regression that motivated the fix. Record which assertion caught it. Never mutate a live installation for this check.

## Run the same proof in development and CI

The following is the target contract; the template-based CI runner is not implemented yet. Do not describe a manual receipt as evidence that this CI path exists.

1. Pin the source commit and template snapshot identities. Clone the topology and apply the tested commit through the release and convergence path.
2. Execute the selected cases with the same runner and assertions used during development.
3. Record each case's result, relevant observations, Doctor findings and fresh/repeat timings. Include command output and native run references on failure, without secrets.
4. Clean up owned clones after success or failure. Retain diagnostic artifacts, not running proof VMs.
5. Report a required CI result for the exact tested commit. Any failed or unexecuted selected case fails the result. Exercise this gate with a known failing case before relying on it.

Run the whole affected proof, including commands that transitively consume a changed shared workflow, extension or role. Changes only to documentation must not start the proof CI. Missing dependency coverage must be made explicit; it must not silently produce a green result with no tests. The proof runs only on the PR head. A new push cancels the proof run of the previous push on that PR. It does not run again after the merge.

## Report

State the commit, templates, cases selected and executed, pass/fail results, functional assertions, Doctor result, repeat behavior and cleanup. Identify unsupported cases or harness limitations. Update this skill with reusable lessons; keep command-specific expectations with that command's cases.
