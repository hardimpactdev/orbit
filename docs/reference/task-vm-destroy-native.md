---
title: "Native Task VM destruction"
description: "Destruction of Task VMs within one native parent."
---

# Native Task VM destruction

The Gateway freezes the VM, provider location, guest Node, worker issuer, model router, workspace and Route identities. Admission performs no host action and does not mark the VM destroyed. Unpublished approved work prevents destructive admission.

`operation-task-vm-destroy` owns one native DAG. It checks cleanup availability and stops the VM on its provider host. It then revokes only that guest's and Task group's worker tokens on the admitted issuer. Next it revokes the model key under the shared router lock, deletes provider resources, runs placed Node cleanup and projects the result. Every placed job uses its exact Node target and matching Node label. Provider stop quiesces the guest before credential revocation; it does not ask the guest worker to stop itself.

`removeTaskVmProvider` is a reusable same-root action. It creates no Operation. Incus effects use the canonical native system-command action. The agent only reads power and volume observations. UpCloud effects use the existing private API credential. Fresh provider observations reconcile lost stop or delete replies and confirm both VM and topology-clone absence.

Node cleanup uses `TaskVmNodeRemovalPlan::deletedTaskVm`, placed lower actions and the pure `NodeRemovalRecords` projector. The parent checks that every required action is available before stopping the VM. Node deletion and VM finalization share the parent transaction, and the deletion event is sent after commit. Provider absence alone never proves complete Node cleanup.

Workspace and Route cleanup are not yet composed in this parent. Admission refuses those scopes with `operation.kind_unavailable`. Missing Node cleanup contributors also refuse before provider effects. The VM records and unpublished work remain. No child Operation, executor endpoint or Gateway SSH action supplies a substitute cleanup path.

The old queued destroy job is removed. Ordinary VM admission uses `StartTaskVmDestroyAction`. The synchronous Task workspace deletion entry refuses before effects until its owning Task root composes VM destruction; it cannot start a child or perform provider cleanup in PHP.
