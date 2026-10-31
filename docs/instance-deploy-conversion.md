# Native development deployment

Default development Instances use `operation-instance-deploy-direct` on their
admitted Node and matching Node label. One native run fetches and checks out the
commit, prepares Vite+ when required, runs ordered Project commands, and
verifies the checkout. The Gateway records immutable intent and projects bounded
history. There are no child Operations, receipts or agent actions.

Development steps run as the configured Node account. Swamp owns fixed local
Git, store and runner commands. The admitted Node platform and architecture
select the Vite+ recipe. Node architecture is `x86_64` or `arm64`; the local
store installer maps it to artifact names. The deadline includes every admitted
command timeout plus 900 seconds of infrastructure time.

Git credentials and registry settings stay inside private callbacks. Scripts
come from the Project reader and must match admitted digests and timeouts.
Required failures stop the list and return a redacted tail of at most 2 KiB;
best-effort failures add warnings. Only success advances the seed.

A checkpoint records an effect before launch and clears after its typed result.
Shared admission marks a fresh Operation running before starting its native
root. A fresh invocation with no checkpoint can start. An existing inactive
checkpoint fails `deployment.interrupted` without repeating effects. On
cancellation of an active app step, a read-only local process scan must prove
its exact Operation/run runner is gone. It then reports interrupted failure with
the saved step and unknown outcome. An active or unreadable runner retains the
hold. No probe kills or adopts a process.

Swamp owns the minute automatic policy, development discovery, backoff and keyed
admission. Policy discovery uses Gateway eligibility. The serving behavior must
contain its development resource; a source reload acknowledgment alone does not
establish that an existing behavior registry refreshed.

Production deployment returns `operation.kind_unavailable` until its native root
lands. Rollback remains production-only; development refuses
`deployment_config.unavailable`. Kit is deleted and there is no compatibility
path.
