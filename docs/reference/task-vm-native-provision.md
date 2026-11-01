---
title: "Native Task VM provision"
description: "One native root on Node209 owns provider creation, pinned SSH, network enrollment and Pi readiness."
---

# Native Task VM provision

The Gateway validates the allocated VM and captures its provider, host, recipe image, resources, guest Node and Pi Process as one intent. It starts `operation-task-vm-provision`. Repeated admission returns the same open Operation.

All steps run on Node209. Provider observation feeds a pure guest-pin decoder. The decoder passes the public guest SSH map as an input to the enrollment child, so official SSH globals do not depend on another step’s outputs. The reusable `task-vm-provision` child takes the authoritative `operation_id`, `kind` and `node_id`, full public `intent`, and descriptor-only `execution_secrets`. It returns the typed `steps.<call>.outputs.result` resource. The owning root prints its own terminal JSON. Tasks parents retain source checkout and setup ownership.

The Incus provider child observes the exact VM before creating or starting it. An existing VM must match its admitted image and identity. Native child resume can re-observe a lost response without allocating another VM or selecting an older image. It does not reopen a terminal Gateway Operation. Incus effects use official pinned SSH to the host. UpCloud provision returns `operation.kind_unavailable` until its vault-backed host-key bootstrap and supported network rollback are complete; no UpCloud provider or guest action starts.

The workflow prepares the guest account, sudo, observer and network through official `@swamp/ssh`. It installs Node209's existing public key, starts OpenSSH and reconciles guest TCP22 rules for Node209's WireGuard address. A trusted provider observation supplies the guest's public host key. The workflow verifies the private WireGuard SSH target with that full pin before model and Pi actions. Plain task VMs run no Swamp worker and receive no worker token.

Guest WireGuard preparation returns a public peer key. The canonical hub action publishes that exact key for the admitted guest and preserves unrelated peers. The hub private key stays on the hub. Provider, peer, hub and runtime actions remain visible native steps. No step calls the Gateway to read intent or report progress.

Model issue and Pi runtime preparation are child workflows in the same native root. Secrets resolve from vault descriptors at the consuming step. Pi preparation verifies the pinned artifact and readiness before returning success. A failed provider, network, model or runtime step cannot produce a ready VM result.

Native failure and cancellation branches revoke any attempted model issuance, restore the same captured hub peer with the prepared key, and restore the guest network snapshot. The provider VM and snapshot remain available after failure. Successful enrollment removes the snapshot; runtime always removes its temporary artifact and script staging directories before returning readiness.

The terminal projector records the public host pin through the same `RecordNodeSshHostKeyAction` used by `POST /api/v1/nodes/{node}/ssh-host-key`. A conflicting stored pin refuses publication. It then records the guest WireGuard key, observer identity, Process state and VM readiness from the terminal result. Failure retains the VM and its cleanup intent; provider absence alone never proves complete destruction.

`AllocateTaskVmAction::capture(Task $group)` records placement inside the owning Task admission transaction without starting a second VM Operation. The caller captures the same provision intent once. Ordinary allocation starts the VM root in its own admission transaction. Neither path grants a Node access edge or creates a guest worker.

## Incus destruction

The native destruction root first observes provider presence and stops a running VM. It requires revocation of this VM's model key before provider deletion. After confirmed absence, it runs canonical Node removal under the same root identity. Cleanup for a deleted machine performs no guest probe or SSH action; external participants remain required. Only full native success removes Node and Process records, marks the VM Destroyed and clears its credentials. Failure retains cleanup intent and credentials.

Owned workspaces, Routes and unpublished approved work retain their admission refusals. Their parent must complete removal before this VM can be destroyed. UpCloud provision and destroy stay unavailable for their follow-up.
