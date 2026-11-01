# Task VM lifecycle

Task VM provider actions run on Swamp Node209. Incus commands reach the admitted host through official `@swamp/ssh`. UpCloud HTTPS requests run on209 with a descriptor from the shared vault copy. Plain Task VMs have no Swamp worker or token.

`task-vm-provider-power` accepts the authoritative `operation_id`, `node_id`, `kind`, full public provider `intent`, descriptor-only `execution_secrets`, and an `action` of `start`, `stop` or `delete`. The public provider intent binds `task_vm_id`, `name`, `provider`, `provider_id`, `vm` and `host_ssh_execution`. Incus uses the recorded project/name; UpCloud uses the recorded UUID, title and `orbit-task-vm` label.

Each run observes the VM, performs one action if required, and observes its postcondition once. A transition that is still pending fails that native step. Resume the native run after inspecting its report; there is no family polling loop or hard-stop fallback. Deletion requires stopped power. UpCloud deletion removes the server storage and backups with the provider's delete request.

The child exposes `steps.<call>.outputs.result`: `{task_vm_id,provider,provider_id,action,running,absent,settled:true}`. This is a provider receipt, not full Task VM readiness or cleanup success.

`task_vm.resume` then checks the admitted Pi executable digest and probes the local Pi capabilities endpoint over official SSH. Its bearer stays in the existing guest file `/home/orbit/.pi/agent/orbit-token`. The root prints its own public terminal JSON at `resume/result`; the shared subscriber projects Ready only from that complete result.

`tasks.park` retains the existing idle external group, published PR and workspace eligibility rules. Its terminal JSON is at `park/result`. Confirmed stopped power records Parked and WaitingForReview. Failure clears stop confirmation without claiming Parked.

Core native workflows, guards, dependencies, resume and vaults provide the control flow. Official SSH provides the remote execution and existing pins. No family Gateway step client, transport, listener, lock or retry supervisor is used.

## Node209 SSH access

Provision and resume converge OpenSSH, the canonical209 authorized key and a guest firewall rule allowing TCP22 only from209's WireGuard address10.44.0.15. The managed rules precede broad WireGuard access. Other authorized keys and unrelated firewall rules remain intact.

For Incus, these steps execute through the admitted host, so a missing guest pin or blocked guest SSH does not prevent repair. The host supplies the public host key. The child verifies officialSSH from209 using that full pin. Terminal projection records it with `RecordNodeSshHostKeyAction`, the same action as `POST /api/v1/nodes/{node}/ssh-host-key`; it refuses a changed stored identity.

`php artisan task-vms:resume <VM-ID>` admits the same lifecycle for a Parked or Ready VM. Ready supports convergence without a stop/restart. No guest-worker enrollment is issued. An active earlier VM Operation prevents a second lifecycle root. For a Parked VM, the native result must also confirm the existing Pi runtime before the VM becomes Ready. For an already Ready VM, convergence confirms SSH only and preserves its runtime state; a failed SSH convergence does not mark that running VM Failed.

The hub's task-VM subnet policy must also admit209 TCP22. Guest readiness does not establish hub reachability by itself. Historical Gateway-only hub policy blocks209 even while sshd listens.

Image admission installs the canonical209 public key. Image preparation enables `ssh.service`; the smoke VM must report the service active before image promotion. The existing image executor remains otherwise unchanged by this urgent SSH repair.

Image bootstrap now makes one native cloud-init observation. If Incus guest access is not ready, inspect and resume the native boot step. The removed custom readiness loop is not replaced by a sleep or retry supervisor.
