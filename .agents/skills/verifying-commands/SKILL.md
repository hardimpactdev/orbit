---
name: verifying-commands
description: Use when defining or running deterministic VM proofs for an Orbit command, including the cases shared by local development and CI. For terminal rendering alone, use verifying-cli-output.
---

# Verify an Orbit command on a VM topology

Prove the command's documented behavior with executable assertions on isolated real Nodes. A successful Operation or matching configuration file is not sufficient when the command promises an observable effect.

Use this with [developing-commands](../developing-commands/SKILL.md). The target CI and template lifecycle is in [feature-flow](../../../docs/reference/feature-flow.md). Existing tooling starts at [the proof harness](../../../swamp/proof/README.md). Extend that harness instead of building a second local or CI runner.

## Define the cases

Read the command's page and chart before writing assertions. Cover the call with required arguments only, each optional flag, error paths and interactions that can change the outcome. Keep cases beside the chart in the planned `<command>.cases.yaml` format once its runner exists; until then, use the existing fixture format and identify gaps explicitly.

Each case specifies:

- The initial recorded and observed state, including the relevant topology participants.
- The command and inputs, expected exit status and public result.
- The recorded state, Node state and functional effect to assert after completion.
- What must remain unchanged, including unmanaged resources and state after a refusal.
- What repeating the request must do under the command's documented contract.

Setup can use other commands or shared fixtures, but the case must invoke the command under test through its real interface. A CLI contract needs the built CLI from the tested commit; an API-only proof does not establish CLI behavior.

## Write assertions that detect a broken command

Derive expected results from the documented contract, independently of the implementation's reported success. Check the Gateway record, the observed Node state and the promised effect. Doctor must report no drift for the records the case touched.

For `firewall:allow`, use an isolated listener and probe Nodes. Confirm the listener is healthy through a control probe, then establish that the tested port is blocked over the test path before the command. After the command, the allowed source must reach it; a source outside a restricted rule must still be blocked. Check the protocol, range, label and rule order where the case exercises them. Test both address families when the command promises both.

The current minimal topology has a broad unmanaged `proof-network` allowance. A probe admitted by that rule cannot prove the new rule works. Arrange the case's test traffic so no broader rule admits it, without removing management access or changing another case's shared topology.

Compare relevant state before and after a refusal. For a repeat, assert no additional managed resources or configuration changes. New Operation history may be expected; do not compare the entire database byte for byte. Distinguish a documented duplicate refusal from replay with the same idempotency key. Neither should create duplicate Node state.

Wait for observable readiness within a bounded deadline. Report infrastructure failures separately from failed command assertions, but neither is a passing case. Include setup, failed assertions and cleanup in the receipt.

Prove the test can fail. For example, on an isolated checkout remove the mutation and confirm the functional assertion fails, or use the real regression that motivated the fix. Record which assertion caught it. Never mutate a live installation for this check.

## Run the same proof in development and CI

The following is the target contract; the template-based CI runner is not implemented yet. Do not describe a manual receipt as evidence that this CI path exists.

1. Pin the source commit and template snapshot identities. Clone the topology and apply the tested commit through the release and convergence path.
2. Execute the selected cases with the same runner and assertions used during development.
3. Record each case's result, relevant observations, Doctor findings and fresh/repeat timings. Include command output and native run references on failure, without secrets.
4. Clean up owned clones after success or failure. Retain diagnostic artifacts, not running proof VMs.
5. Report a required CI result for the exact tested commit. Any failed or unexecuted selected case fails the result. Exercise this gate with a known failing case before relying on it.

Select cases from changed executable inputs and their dependencies. A shared workflow, extension or role can affect several commands. Changes only to documentation must not start a VM job. Missing dependency coverage must be made explicit; it must not silently produce a green result with no tests. Apply the same selection and required check to the merge queue's combined commit.

## Report

State the commit, templates, cases selected and executed, pass/fail results, functional assertions, Doctor result, repeat behavior and cleanup. Identify unsupported cases or harness limitations. Update this skill with reusable lessons; keep command-specific expectations with that command's cases.
