# Direct Task VM Incus effects

`operation-task-vm-incus` runs one admitted create, start, stop or delete on
`orbit-node-<node_id>`. There is no fallback worker. Until that Node's worker is
live and its canary passes, its kinds keep the previous polled Swamp writer. No
foreign VM action runs on Node 209 for a placement canary.

The Gateway captures the exact VM command and delivered agent SHA-256 at
admission. Direct deletion requires its owning destruction intent and any
model-key revocation first. The shared direct read exposes that safe intent, and
only the Gateway's start response binds the root run ID. The worker checks the
kind, VM identity, command variant, binary ownership, parent directories and
admitted digest before invoking
`sudo -n -- /usr/local/bin/orbit-agent
command incus.vm`. The command retains
its existing 900-second deadline.

The step uses the Node user's existing sudo, without a socket or broker. Native
Swamp placement, behavior locking, cancellation and recovery own execution. The
command is convergent: native recovery repeats the same admitted identity. A
lost response never supplies a successful result. The worker accepts only
`ready: true` or a bounded error code; it does not retain raw output or stderr.
No credential or vault is required by these four host effects.

The Gateway's per-kind `swamp.direct_writers` setting defaults to `polled` and
is recorded immutably as `task_vm_incus_writer`. Prove each kind on a safe VM
before takeover, then remove its previous transport after production succeeds.
The first slice prepares these workflows and admission contracts; it does not
change a live writer or delete an unproven path.
