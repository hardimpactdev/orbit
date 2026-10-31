---
title: "Native Task VM provision"
description: "One native root owns provider creation, exact Node enrollment, worker readiness and runtime preparation."
---

# Native Task VM provision

The Gateway validates the allocated VM and records its host, recipe image, resource limits and deadline as one intent. It starts `operation-task-vm-provision`. Repeated admission returns the same open Operation.

The workflow creates the provider VM, prepares enrollment on its admitted host, proves the guest's exact `orbit-node-N` worker and prepares Pi through the shared Process lower action. Every placed job also requires the matching `node` label. A task-group worker does not satisfy this identity.

The host root records the guest SSH public identity after bootstrap and its WireGuard public key before hub publication. Hub publication runs on the Gateway through the canonical `wireguard-hub-N` behavior lock and the admitted pinned SSH transport to the hub. Its private material uses only `configuration` (`node.vpn_configuration`); the old rendered enrollment network source is removed. A failed hub result stops peer publication.

After network publication succeeds, the host root records `node.network_enrolled=true` before worker issuance. The shared enrollment projector activates only the admitted guest Node. The VM, app-dev role and Pi Process remain provisioning until native worker and runtime confirmation succeeds. This checkpoint adds no Node access edge. The shared boundary authorizes each admitted participant for this parent run.

The enrollment lane supplies the provider and enrollment lower actions. They run inside the provision root. Missing preparation refuses before provider allocation. Provision does not admit a Node, Process or enrollment child Operation.

Native admission requires the provider and enrollment snapshots to agree on the VM, executor, Gateway, issuer, guest, model router and hub snapshot before provider work starts.

Private worker and runtime material uses the shared declared-participant path. Private values stay inside callbacks. Native outputs contain only public placement and bounded status.

The Gateway records the native result and marks the VM ready or failed. It does not execute provider work or validate native stage receipts again. Cancellation and failure leave the VM available to its separate destruction intent; they never fabricate provider absence.

`AllocateTaskVmAction::capture(Task $group): TaskVm` records placement for a Task parent without starting a separate VM Operation or reserving enrollment. The Task admission calls it inside its own transaction, then captures enrollment and private bindings with `providerCreate: true`. Ordinary VM admission uses `execute`, which records placement and its native VM parent in one transaction. Both paths share the same placement rules.
