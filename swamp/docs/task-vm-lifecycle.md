# Task VM lifecycle

Task VM provider actions run on Swamp Node 209. Incus commands reach the admitted host through official `@swamp/ssh`. TypeScript validates intent and decodes the answers; no script ships. Plain Task VMs have no Swamp worker or token.

`task-vm-provider-power` accepts the authoritative `operation_id`, `node_id`, `kind`, full public provider `intent`, descriptor-only `execution_secrets`, and an `action` of `inspect`, `start`, `stop` or `delete`. The public provider intent binds `task_vm_id`, `name`, `provider`, `vm` and `host_ssh_execution`. Incus uses the recorded project/name.

Each run observes the VM, performs one action if required, and observes its postcondition once. A transition that is still pending fails that native step. Resume the native run after inspecting its report; there is no family polling loop or hard-stop fallback. Deletion requires stopped power.

The child exposes `steps.<call>.outputs.result`: `{task_vm_id,provider,action,running,absent,settled:true}`. This is a provider receipt, not full Task VM readiness or cleanup success.

`task_vm.resume` then checks the admitted Pi executable digest and probes the local Pi capabilities endpoint over official SSH. Its bearer stays in the existing guest file `/home/orbit/.pi/agent/orbit-token`. The root prints its own public terminal JSON at `resume/result`; the shared subscriber projects Ready only from that complete result.


Core native workflows, guards, dependencies, resume and vaults provide the control flow. Official SSH provides the remote execution and existing pins. No family Gateway step client, transport, listener, lock or retry supervisor is used.

## Node 209 SSH access

Provision and resume converge OpenSSH, the canonical 209 authorized key and a guest firewall rule allowing TCP22 only from 209's WireGuard address 10.44.0.15. The managed rules precede broad WireGuard access. Other authorized keys and unrelated firewall rules remain intact.

For Incus, these steps execute through the admitted host, so a missing guest pin or blocked guest SSH does not prevent repair. The host supplies the public host key. The child verifies official SSH from 209 using that full pin. Terminal projection records it with `RecordNodeSshHostKeyAction`, the same action as `POST /api/v1/nodes/{node}/ssh-host-key`; it refuses a changed stored identity.

`php artisan task-vms:resume <VM-ID>` admits the same lifecycle for a Parked or Ready VM. Ready supports convergence without a stop/restart. No guest-worker enrollment is issued. An active earlier VM Operation prevents a second lifecycle root. For a Parked VM, the native result must also confirm the existing Pi runtime before the VM becomes Ready. For an already Ready VM, convergence confirms SSH only and preserves its runtime state; a failed SSH convergence does not mark that running VM Failed.

The hub's task-VM subnet policy must also admit 209 TCP22. Guest readiness does not establish hub reachability by itself. Historical Gateway-only hub policy blocks209 even while sshd listens.

Image admission installs the canonical 209 public key. Image preparation enables `ssh.service`; the smoke VM must report the service active before image promotion. The existing image executor remains otherwise unchanged by this urgent SSH repair.

The image executor retains its existing boot, cleanup and progress behavior. This repair adds placed 209 SSH enable/check steps; it does not claim the remaining image conversion defects are closed.

## Start and release

A task start (`tasks.provision`) copies the base snapshot of the host and writes the identity of the slot; the release (`task_vm.slot_release`) deletes the VM, its seed clone and its identity volume. Both run as Ansible phases of `swamp/ansible/task-vm-host.yml`. Power changes of a parked VM run in `task-vm-provider-power`. There is no provision, enrollment or destroy workflow.

Publication releases the task VM. Later build, fix and review work claims a fresh task place from the base at the task head; full pools wait for up to two hours.
