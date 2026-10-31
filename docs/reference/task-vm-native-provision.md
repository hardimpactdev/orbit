---
title: "Native Task VM provision"
description: "One admitted Task VM intent, placed provider and enrollment steps, and terminal result projection."
---

# Native Task VM provision

The command-step conversion supplies the full public intent at run admission. Steps act on that recorded intent and return public results. They do not fetch intent, obtain private material, or post progress through the Gateway. See [Swamp command steps](/reference/swamp-command-steps) for the shared vault and terminal contracts.

The outer workflow inputs are `{operation_id, kind, node_id, intent, execution_secrets}`. `node_id` remains the admitted root Node. Each action's placement comes from its own admitted participant; it does not change the root identity. The direct declaration uses `workflow_inputs: true` and a separate fixed `terminal_result: {job, step}` selector. The shared builder adds authoritative Operation, kind and root Node IDs last.

`TaskVmProvisionWorkflowInputs::compose` combines the public provider allocation with the enrollment owner's envelope. Both parts must name the same VM, provider, host executor, guest, Gateway, issuer and model router. The composer copies only the named provider fields. It does not carry old private references or bindings into native inputs. Secret values use the shared `SwampCommandStep::executionCopy` helper; inputs contain only names of vaults and keys.

`TaskVmProvisionWorkflowInputs::capture(vm, allocation)` prepares that envelope from an already captured allocation. It uses the canonical enrollment supplier to capture public data and synchronize model and Pi execution copies. It checks the public identities before requesting copies. A shared vault refusal propagates before any envelope is returned; the parent must not start a run after that refusal. This method does not allocate again, start an Operation, or supply the still-missing worker and hub actions.

## Provider commands

`task-vm-provider-incus` is a reusable native fragment. It receives public intent and root identity; it needs no secrets. Each command runs on the exact host with both its target and string Node label. It observes the exact VM, checks the admitted image, clones an immutable topology seed when required, initializes the VM, removes seed templates from public metadata, starts the VM, and observes the running guest address.

Each action is a separate visible `command/shell` step. Typed behaviors only shape public data and validate observations. Native dependencies and guards skip satisfied state. Initialize, clone and start read current state when executed, including after native resume. A lost start reply therefore does not repeat a successful start. An unavailable address fails after one final observation; there is no family sleep or readiness loop.

Native resume retains successful step outputs. When external state changes after a typed observation fails, resume from its read step to obtain fresh data. For a newly available guest address, use `swamp workflow resume task-vm-provider-incus --run RUN_ID --from inspect-created-vm --job provider`. After supplying a missing topology seed, use `--from observe-seed --job provider`. After correcting the initial VM observation, use `--from observe-vm --job provider`. These commands reset the selected read and its dependents; they do not recreate earlier successful actions. A failed start command already reads current state on an ordinary `resume --run RUN_ID`.

The fragment exposes a typed public `provider` result with its address, admitted image, provider identity and settled state. The enclosing provision root can consume this result through a native workflow step. A failed seed clone or a foreign image prevents VM initialization. Provider absence is not cleanup evidence.

## Enrollment and results

The pending parent integration composes the canonical enrollment and model fragments. Enrollment owns the bootstrap identity, network configuration, worker enrollment and runtime actions. Model issuance uses the shared router identity. These fragments retain their admitted placement; provision does not start child Gateway Operations or create a second model-key writer.

The available `task-vm-pi-install` fragment takes `{operation_id, kind, node_id, intent}`. It runs on `intent.guest_node_id` and retains the owning root's `node_id`. After canonical artifact delivery, it inspects, installs when needed, and verifies `runtime.pi_sha256`. Its public result is `{task_vm_id, sha256, installed: true}`. Installation alone does not activate the Process or mark the VM ready. Upload remains blocked on the shared immutable artifact transport; no local path or family upload protocol replaces it.

### Cutover and projection

All old and new router writers must switch together before activation. The old custom behavior and the new built-in shell behavior have separate native lock identities, even when they use the same router name. This includes issuance from provision, Tasks provision, enrollment and runtime, plus destroy revocation. A partial parent cutover cannot provide shared serialization. Keep this conversion draft until the coordinated switch and old-helper deletion are complete.

The parent integration must end with a result step that prints one public JSON object. The shared subscriber reads native history once and invokes `TaskVmProvisionProjection`. At that cutover, provider observations and the admitted guest's public identity move to terminal projection. Only successful enrollment makes the VM, app-dev role and Pi Process ready. Failure must retain observed provider metadata for the separate destroy intent without claiming cleanup.

`AllocateTaskVmAction::capture(Task $group): TaskVm` records placement for a Task parent without starting a separate VM Operation. The Task parent captures enrollment in its own transaction. Ordinary VM admission uses `execute`, which records placement and the VM parent in one transaction. Both paths use the same placement rules and the existing reentrant model admission fence around the entire outer transaction and after-commit native start.

## Conversion status

The Incus fragment and public composer are executable conversion checkpoints. The registered provision root and projector remain on their existing path until the enrollment fragment, admission envelope and terminal selector are switched together. Shared code used by Tasks remains until its owner consumes the same conversion; its grandfather entries cannot be removed while it still executes.

The shared execution-copy helper uses official Swamp e459 authenticated `vault.put` over WSS. Required vault authority remains absent; source availability does not grant it. The shared terminal subscriber queues the durable `ProjectSwampTerminal` job and uses the same one-history-read helper; this family adds no event listener or poller. Logical Nodes 1 and 18 also need the shared transport for built-in shell steps on proxy Nodes. Synthetic scratch-vault and isolated native tests do not establish live provision readiness. No fresh Task VM admission is implied by this source checkpoint.
