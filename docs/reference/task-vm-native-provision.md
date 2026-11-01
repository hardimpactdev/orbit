---
title: "Native Task VM provision"
description: "One native root on Node209 owns provider creation, pinned SSH, network enrollment and Pi readiness."
---

# Native Task VM provision

The Gateway validates the allocated VM and captures its provider, host, recipe image, resources, guest Node and Pi Process as one intent. It starts `operation-task-vm-provision`. Repeated admission returns the same open Operation.

All steps run on Node209. Provider observation feeds a pure guest-pin decoder. The decoder passes the public guest SSH map as an input to the enrollment child, so official SSH globals do not depend on another step’s outputs. The reusable `task-vm-provision` child takes the authoritative `operation_id`, `kind` and `node_id`, full public `intent`, and descriptor-only `execution_secrets`. It returns the typed `steps.<call>.outputs.result` resource. The owning root prints its own terminal JSON. The Tasks parent composes this child, the shared workspace directory child, then its native source checkout and ordered setup child. Only its complete result advances the task group.

The Incus provider child observes the exact VM before creating or starting it. An existing VM must match its admitted image and identity. Native child resume can re-observe a lost response without allocating another VM or selecting an older image. It does not reopen a terminal Gateway Operation. Incus effects use official pinned SSH to the host. UpCloud uses the provider workflow described below, with credentials from the vault.

The workflow prepares the guest account, sudo, observer and network through official `@swamp/ssh`. It installs Node209's existing public key, starts OpenSSH and reconciles guest TCP22 rules for Node209's WireGuard address. A trusted provider observation supplies the guest's public host key. The workflow verifies the private WireGuard SSH target with that full pin before model and Pi actions. Plain task VMs run no Swamp worker and receive no worker token.

Guest WireGuard preparation returns a public peer key. The canonical hub action publishes that exact key for the admitted guest and preserves unrelated peers. The hub private key stays on the hub. Provider, peer, hub and runtime actions remain visible native steps. No step calls the Gateway to read intent or report progress.

Model issue and Pi runtime preparation are child workflows in the same native root. Secrets resolve from vault descriptors at the consuming step. Pi preparation verifies the pinned artifact and probes the captured Pi URL on its actual WireGuard bind address before returning success. A failed provider, network, model or runtime step cannot produce a ready VM result.

Native failure and cancellation branches revoke any attempted model issuance, restore the same captured hub peer with the prepared key, and restore the guest network snapshot. The provider VM and snapshot remain available after failure. Successful enrollment removes the snapshot; runtime always removes its temporary artifact and script staging directories before returning readiness.

The terminal projector records the public host pin through the same `RecordNodeSshHostKeyAction` used by `POST /api/v1/nodes/{node}/ssh-host-key`. A conflicting stored pin refuses publication. It then records the guest WireGuard key, observer identity, Process state and VM readiness from the terminal result. Failure retains the VM and its cleanup intent; provider absence alone never proves complete destruction.

`AllocateTaskVmAction::capture(Task $group)` records placement inside the owning Task admission transaction without starting a second VM Operation. The caller captures the same provision intent once. Ordinary allocation starts the VM root in its own admission transaction. Neither path grants a Node access edge or creates a guest worker.

## Incus destruction

The native destruction root first observes provider presence and stops a running VM. It requires revocation of this VM's model key before provider deletion. It observes, removes and verifies absence of the custom seed volume for this VM, including when the VM is already absent. After confirmed provider and seed absence, it runs canonical Node removal under the same root identity. Cleanup for a deleted machine performs no guest probe or SSH action; external participants remain required. Only full native success removes Node and Process records, marks the VM Destroyed and clears its credentials. Failure retains cleanup intent and credentials.

Canonical Node protection refuses Database servers, Schedules, configured hosts and protected roles before provider effects. Active guest or task group Operations must settle first. Owned workspaces, Routes and unpublished approved work retain their admission refusals. Their parent must complete removal before this VM can be destroyed.

## UpCloud lifecycle

UpCloud uses the same root envelope and public terminal receipt. The Gateway records the existing `provider_bootstrap` host-key pair before admission and pushes its execution copy through `SwampCommandStep::executionCopy`. Only its public host key enters workflow intent. The UpCloud API token also uses the shared vault helper. No private host key is generated or retained only on Node209, returned in stdout, or placed in argv.

Placed steps list labelled servers, select the exact VM, create only when absent, inspect its identity and image, start only when stopped, and observe once. A list can omit labels; the detail response must prove ownership and the selected UUID before any power change. A lost create response resumes from discovery/observation instead of allocating again. The API's own stop timeout is tool-owned; unsettled observations fail once for native resume. Provider deletion requests attached storage removal and confirms server absence.

### Bootstrap and compensation

Bootstrap uses209's approved SSH identity and the public host key captured before creation. Enrollment uses official SSH for public first access and private WireGuard access. After a failed or cancelled UpCloud enrollment, native compensation revokes model access, restores the captured hub peer and deletes the unready provider through its API. Recovery does not depend on guest SSH after its firewall has restricted TCP22. A failed compensation remains failed and retains Gateway credentials/cleanup intent. A failed provider prefix can discover its exact labelled VM and remove it without changing peer or hub state.

UpCloud destruction uses the same model-revocation-before-provider-delete and canonical Node cleanup order as Incus. It skips Incus topology-volume actions. Success alone clears stored credentials and records; provider absence alone never proves complete cleanup. Destroy still refuses owned workspaces, Routes and approved work that has not been published.

The old Gateway UpCloud lifecycle path is deleted, including its provider mutation methods, orphaned server mutation and polling helpers, and obsolete host-key user-data renderer. Gateway server and template helpers only read provider state. Image builders and sweep readers retain their separate ownership; it adds no provider fallback or guest worker. Admission is enabled only with the complete provider, enrollment compensation and destroy paths in this change. Live use still follows the admitted release/window.

A Tasks cleanup parent captures its exact plain workspace through `TaskVmDestroyIntent::captureForWorkspace($vm, $instance)`. This admits only the Instance owned by the same VM group and Project. Other Instances, Routes and protected Node resources still refuse admission. Capture retains the workspace row. The parent runs workspace teardown before the existing `task-vm-destroy` child, then removes workspace records and calls `TaskVmDestroyProjection::record($vm, $captured, $result)` in its terminal transaction. Recording requires the same captured VM and complete cleanup result, and rechecks that no workspace or foreign resource remains. It creates no child Operation. Standalone destroy retains its strict capture and Operation ownership checks.
