---
title: "Native Task VM destruction"
description: "Destruction of Task VMs within one native parent."
---

# Native Task VM destruction

The Gateway freezes the VM, provider location, guest Node, workspace and Route identities. Admission performs no host action and does not mark the VM destroyed. Unpublished approved work prevents destructive admission. The native parent checks ownership again before provider changes and records one terminal result.

`removeTaskVmProvider` is a reusable same-root action. It creates no Operation. It checks exact provider ownership and proves the VM and its topology clone absent. Incus deletion requires the provider owner's native system-command action. The agent may read power and volume observations; it performs no mutation for this action. That result proves provider removal only.

Complete guest cleanup also requires the enrollment owner's worker stop and credential revocation actions, the action that revokes the model key, and placed Route and Node cleanup actions. These participants must run in the same native root. A child Operation, an executor endpoint and a Gateway SSH action are not supported participant interfaces.

Node cleanup uses the snapshot from `NodeRemovalPlan::deletedTaskVm` and the pure `NodeRemovalRecords` projector. The parent checks that every required effect and compensation action is available before provider removal. Each effect retains its exact Node placement. Node deletion and VM finalization share the parent transaction, and the deletion event is sent after commit. A VM that requires an unavailable participant is refused before provider effects. Its records and unpublished work remain. Neither provider absence nor an accepted agent configuration proves complete guest cleanup.