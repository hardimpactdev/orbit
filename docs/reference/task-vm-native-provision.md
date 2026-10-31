---
title: "Native Task VM provision"
description: "One admitted Task VM intent, placed provider and enrollment steps, and terminal result projection."
---

# Native Task VM provision

The command-step conversion supplies the full public intent at run admission. Steps act on that recorded intent and return public results. They do not fetch intent, obtain private material, or post progress through the Gateway. See [Swamp command steps](/reference/swamp-command-steps) for the shared vault and terminal contracts.

The outer workflow inputs are `{operation_id, kind, node_id, intent, execution_secrets}`. `node_id` remains the admitted root Node. Each action's placement comes from its own admitted participant; it does not change the root identity. The direct declaration uses `workflow_inputs: true` and a separate fixed `terminal_result: {job, step}` selector. The shared builder adds authoritative Operation, kind and root Node IDs last.

`TaskVmProvisionWorkflowInputs::compose` combines the public provider allocation with the enrollment owner's envelope. Both parts must name the same VM, provider, host executor, guest, Gateway, issuer and model router. The composer copies only the named provider fields. It does not carry old private references or bindings into native inputs. Secret values use the shared `SwampCommandStep::executionCopy` helper; inputs contain only names of vaults and keys.

## Provider commands

`task-vm-provider-incus` is a reusable native fragment. It receives public intent and root identity; it needs no secrets. Each command runs on the exact host with both its target and string Node label. It observes the exact VM, checks the admitted image, clones an immutable topology seed when required, initializes the VM, removes seed templates from public metadata, starts the VM, and observes the running guest address.

Each action is a separate visible `command/shell` step. Typed behaviors only shape public data and validate observations. Native dependencies and guards skip satisfied state. Initialize, clone and start read current state when executed, including after native resume. A lost start reply therefore does not repeat a successful start. An unavailable address fails after one final observation; there is no family sleep or readiness loop.

The fragment exposes a typed public `provider` result with its address, admitted image, provider identity and settled state. The enclosing provision root can consume this result through a native workflow step. A failed seed clone or a foreign image prevents VM initialization. Provider absence is not cleanup evidence.

## Enrollment and results

The pending parent integration composes the canonical enrollment and model fragments. Enrollment owns the bootstrap identity, network configuration, worker enrollment and runtime actions. Model issuance uses the shared router identity. These fragments retain their admitted placement; provision does not start child Gateway Operations or create a second model-key writer.

The parent integration must end with a result step that prints one public JSON object. The shared subscriber reads native history once and invokes `TaskVmProvisionProjection`. At that cutover, provider observations and the admitted guest's public identity move to terminal projection. Only successful enrollment makes the VM, app-dev role and Pi Process ready. Failure must retain observed provider metadata for the separate destroy intent without claiming cleanup.

`AllocateTaskVmAction::capture(Task $group): TaskVm` records placement for a Task parent without starting a separate VM Operation. The Task parent captures enrollment in its own transaction. Ordinary VM admission uses `execute`, which records placement and the VM parent in one transaction. Both paths use the same placement rules and the existing reentrant model admission fence around the entire outer transaction and after-commit native start.

## Conversion status

The Incus fragment and public composer are executable conversion checkpoints. The registered provision root and projector remain on their existing path until the enrollment fragment, admission envelope and terminal selector are switched together. Shared code used by Tasks remains until its owner consumes the same conversion; its grandfather entries cannot be removed while it still executes.

Official Swamp e459 currently refuses remote vault-put stdin. The orchestrator owns that upstream blocker. Logical Nodes 1 and 18 also need the shared built-in shell proxy transport. Synthetic scratch-vault and isolated native tests do not establish live provision readiness. No fresh Task VM admission is implied by this source checkpoint.
