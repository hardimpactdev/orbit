# Task VM Operations

`task_vm.image_build` directly starts `operation-task-vm-image-build` (UUID
`878a384f-8b8a-4066-bd17-dcad8eac3e86`). One root places admission and image
steps at the exact admitted build host, which downloads the pinned Pi artifact
through the shared private-material read and verifies its digest before guest
use. Recipe, smoke, seed finalization, promotion and pruning are native steps.
It has no claim, lease, keyed receipt, child Operation, Gateway observation loop
or polling registration. The Gateway projects bounded phase/fingerprint results
and the terminal build state. Rollback is a revert of the paired cutover
commits. The former image helper kinds, including `task_vm.image_finish_seed`,
have no independent caller or registration: seed finalization is a step of this
root. The read-only `image.owner` check runs as the image's pinned `orbit` UID
and GID 1000. Root commands and mutation supervision start as root; setting
`SUDO_USER` does not change a read command's effective identity. The retained
paths below apply to other Task VM kinds, including UpCloud images.

Publication uses an attempt-scoped alias, then atomically promotes its verified
fingerprint to the stable candidate alias. A new stock attempt never reuses an
older candidate fingerprint. Repeating a publication within the same attempt
reuses its fingerprint; failure before candidate promotion preserves the prior
candidate. The public base alias changes only after smoke and seed checks.

Package installation may re-execute the guest systemd manager. An uncertain
`image.inspect-phase` read is observed again within the phase's 1440-second
budget. The budget starts after `image.start-phase` returns, so artifact
delivery does not consume it. Sixty seconds without a successful phase read ends
the step; a successful read resets that outage budget. Each read is bounded by
the time remaining. The workflow keeps the expected boot ID and never restarts a
phase because its status read was uncertain. Other command failures still end
the native step.

The Gateway records VM, runtime, image and host sweep intent. This family drives
their effects through fixed Operations. Discovery dispatches a kind and worker;
the placed family claims its Operation and saves that assignment before effects.
A retry reuses the assignment and checks a terminal saved receipt against the
Gateway. The existing task-group executor remains the Tasks family.

The paired Gateway change registers each kind's transport, command, input
bindings, redacted result fields and completion proof. Parent Operations use kit
B's server-built child contexts and admission adapters. Swamp sends a child key,
then yields through the shared wait endpoint. The ordinary runner claims
children and resumes their parent. Every parent wait names its own admitted
child. DB-only intent children provide the first observation boundary.

A lost Node reply is resolved through the shared receipt read before another
run. The target receipt layer owns effect keys and deduplication. This family
adapts its results through `OperationEffectTransport` and `AgentEffectReceipts`.
It stores child identities and completed stages in native Swamp resources. A
cancelled or expired parent settles admitted children before becoming terminal.

## Protected inputs

| Source                         | Owner                                      | Use                                                      |
| ------------------------------ | ------------------------------------------ | -------------------------------------------------------- |
| `upcloud.api`                  | UpCloud VM, image attempt or Gateway sweep | Fixed owned server and template API actions              |
| `task_vm.model_key`            | Task VM                                    | Model-key issuance, revocation and runtime configuration |
| `task_vm.pi_token`             | Task VM                                    | Private Pi runtime configuration                         |
| `task_vm.package_cache_github` | Cache host                                 | Package downloads from the admitted Project snapshots    |
| `task_vm.image_bootstrap`      | UpCloud image attempt                      | The attempt's encrypted builder and smoke host keys      |

Gateway source resolvers check the target type and owning record before
hydration. Pinned Pi delivery uses the existing protected receiver. Recipe
payloads are public JSON strings so nested empty maps survive Eloquent casts and
retain the native agent schema's object/list distinction.

## Children and results

Lifecycle parents own provider power, Node enrollment, runtime preparation and
removal children. Workspace Routes use the owning `route.destroy` admission with
the admitted route, Instance and Node identities. Record cleanup performs only
DB projection after that exact child proof. Runtime preparation proves model-key
configuration, pinned Pi configuration, the normal Pi Process and readiness.
Runtime intent includes the Gateway-recorded fixed Pi Process ID. After Pi
configuration, Swamp admits `pi-process-owner` as the owning `process.create`
child with only `{process_id}`. A failed or uncertain Process child prevents
runtime readiness. Current Pi artifacts need no upload or install children;
completion still requires model-key, configuration, Process and readiness proof.
The obsolete `task_vm.pi_process` Gateway effect is not registered.

Parking proves stopped power. Cache preparation proves host initialization and
its read-only mount before seeding the admitted Project inventory. Destruction
retains unpublished work until preservation or confirmed VM loss permits
removal. The Gateway checks preservation again at the deletion boundary. Forced
removal requires the stamped failed worker-stop and power-off children. Optional
lifecycle branches use exact-key completion assertions; no unsupported
per-variant contract is registered.

The direct Incus image root above owns recipe supervision, smoke, topology seed
and final promotion. Successful native steps provide its execution history.

`task_vm.upcloud_image_build` directly starts
`operation-task-vm-upcloud-image-build` (UUID
`ede34ced-7d0e-4764-84c1-cfc533d9e980`). One native root places every API and
guest step at the admitted Gateway Node worker. It uses the same recipe observer
and artifact uploader as Incus images. Swamp generates guest host keys, stores
them through the existing encrypted output boundary and uses the pinned official
`@swamp/ssh` library for strict host-key verification. Private keys and provider
tokens stay in callback-local memory and the encrypted Gateway record; they
never enter native arguments or resources. The SSH library is bundled from
commit `d6f7afdcd6d04a63fdbc6ed56c403832770f67a6` of
swamp-club/swamp-extensions, matching its published `2026.10.23.1` operations
source. The Node trust linter allows only that exact immutable operations URL;
mutable refs, other SSH entrypoints and third-party workflow execution remain
refused. This exception is included in the credential/access review.

Server adoption requires the exact title, hostname and image-attempt label.
Smoke failure prevents promotion and removes only this attempt's servers and
unverified template. A lost create reply is observed through those provider
identities before another mutation. Pruning retains the two newest verified
templates and templates pinned by current allocations. Provider waits and stop
escalation stay inside native steps. The old image child kinds, PHP command/read
relays, claim registration and polled discovery entries are deleted together.

Each host sweep is one direct `task_vm.sweep_host` root in
`operation-task-vm-sweep`, placed on `orbit-node-<admittedNodeId>` with the
matching Node label. The sweep children (`sweep_observe`, `sweep_delete`,
`sweep_delete_upcloud`, `sweep_stop_upcloud`), Gateway inventory/transport/read
route and polled claim/discovery registrations are deleted. The Gateway records
host scope and expired build retirement intent; Swamp reads inventory, rechecks
current DB ownership before deletion and reports strays. The native UpCloud step
uses callback-local `upcloud.api` material only on the admitted Gateway worker,
checks the exact UUID/name/hostname/label, waits for stopped power, and observes
absence after deletion. It never forwards credentials to a foreign worker.

Shared Gateway calls use the Node worker's installed public CA at
`/usr/local/share/ca-certificates/orbit-root.crt`. A retained explicit model CA
setting remains an override; source acceptance must verify the served default
and the sweep model's saved arguments before canary admission.

Native terminal results contain bounded `observed_names` and `deleted_names`,
plus `ready:true` or a bounded failure code. An unreadable inventory returns a
failure without an observation. Two distinct successful inventory observations
must miss a VM before its row fails; neither native root recovery nor an old
terminal result replay can count twice or restore stale missing evidence. No
native queue override or family child wait is added. The existing 30-minute host
deadline is immutable across native resume.

Results are closed scalar fields such as `ready`, `changed`, Node/Process IDs,
fingerprints, template UUIDs and native phase state. Each cache fill batch uses
bounded parallel scalar lists for archive keys/hashes and failures. The Gateway
partitions input so worst-case receipts fit the shared 1 KiB string and 256 KiB
result limits. The host-wide parent settles its fill children and retains only
outcome and failed child identities. Failed and cancelled completions let the
Gateway derive skipped entries from child rows. The Gateway validates and
redacts them before storage and projects partial cache success only from a
proven fill receipt.

Cache batch conflict maps retain the Gateway's admitted JSON value. Empty PHP
maps arrive as `[]`; populated maps contain archive keys and string reasons. The
input parser accepts those two shapes and refuses nonempty arrays.

## Required shared interfaces

The family requires kit A/G, B, C, D and I. It registers a dependent kind only
while `OperationKinds::supports` confirms its enforcement. Admission otherwise
returns `operation.kind_unavailable`.

Kit B supplies `OperationFamilyChildren`, `OperationChildAdmission`,
`OperationChildren::requireSucceeded` and the wait endpoint. Kit D supplies
`AwaitOperation::execute(Operation)`. Kit I supplies `OperationEffectTransport`,
target receipt reads and `AgentEffectReceipts::input` for ephemeral sanitization
metadata. The wrappers use that helper on the original invocation; recovery
never rehydrates payload secrets. Promotion and pruning use the same native
receipt path.

The Node owner supplies `node.add` and `node.remove`, with
`ProvisionNodeAction::start` and `RemoveNodeAction::start` returning the
admitted Operation. The Process owner supplies `process.create` through
`CreateProcessAction::admit`. The family awaits the exact returned Operation and
requires the owning kind to use Swamp before invoking its admission. The Gateway
saves that exact Operation ID in the calling effect before awaiting it and
requires success before dependent cleanup. Enrollment also requires the owning
`node.add` result to prove `enrolled: true`. Its start preserves `sshJumpNodeId`
and `taskVmId` before convergence. A bounded wait timeout retains the saved
Operation and Node/VM work; Swamp continues that exact enrollment. These calls
require the matching owning-family changes.

Focused Deno tests exercise ordering, refusals, lost replies, leases,
cancellation, native phase resume, artifact reuse and sweep behavior.
`native_test.py` runs the pinned parent/dispatch/family workflows in a
disposable repository, then resumes a failed family in another Swamp process.
Live verification follows the paired deployment on the orchestrator's designated
harmless dev target.

Provisioning and enrollment first run `task_vm.prepare_enrollment`. The Gateway
reserves the VM Node and records its exact `node-enrollment` / `node.add` child
with internal ProvisionNodeData fields, including taskVmId and sshJumpNodeId.
Swamp requests the recorded key and waits for its owning enrollment receipt
before runtime preparation. A Gateway-recorded already active Node skips that
child. Replays reuse the reservation. The fixed result schema preserves the
boolean `enrolled` and the reserved `node_id` on both fresh responses and
recovered receipts. Both owning Node releases must be deployed before enabling
these admissions; retained callers stay synchronous.

Destruction proves provider absence and Route removals, then runs
`task_vm.forget_workspaces` while the VM still has its recorded Node. Only that
proof unlocks the stored `node-removal` / `node.remove` descriptor. Final VM
cleanup requires owning Node removal proof. The descriptor preserves the
original Node ID after foreign keys are cleared. A VM with no recorded Node
never queries or deletes unrelated workspace records with a null Node.

## Child lifetime and observation waits

The retained workspace-removal and host-sweep callers admit `task_vm.destroy`.
An already queued PHP destruction job only records that intent; it performs no
Node work or wait. Existing workspace teardown, transcript archival and provider
deletion stay in their retained caller until that caller's separate cutover. The
Swamp parent still proves provider absence and workspace cleanup before
admitting its owning Node removal child. Final VM cleanup waits for that child.

Destruction execution cases belong to this family: pending preservation, failed
worker stop and power-off, revocation refusal, lost provider replies, Route
cleanup and Node-removal failure. Gateway tests retain admission, reviewed-work
state, teardown, archival and ownership contracts. Admission or a queued Node
child never proves a destroyed VM.

A VM parent does not cancel a progressing child because the parent's observation
budget elapsed. Child admission and waiting follow the stamped child to its
terminal result, including Node enrollment and Process creation. Later child
admissions remain allowed after a long Node enrollment. Explicit parent
cancellation still settles all children before the parent completes.

The family's existing `deadline_at` bounds its own provider and runtime
observation waits. It is not an outer deadline for child composition.

Provisioning and enrollment checkpoint successful bootstrap observation in Swamp
before preparing Node enrollment. Re-entry while Node or runtime children finish
reuses that proof. It does not probe the already-configured guest with the
original bootstrap deadline. Missing, stopped, wrong-image and expired initial
boots still refuse before enrollment; an unsuccessful probe creates no
checkpoint.

Each dispatch is a native nested `operation-task-vm-dispatch` workflow. It uses
Swamp's default queue timeout. There is no family signal timer, saved queue-wait
resource or automatic cancellation request for an unclaimed child. Operators
cancel the native run with `swamp workflow cancel`; the family adds no separate
queue or cancellation policy.

Both image roots use native placed steps and have no Gateway child observation
loop.

The unused cache-preparation Operation family is retired:
`prepare_package_cache`, `cache_initialize`, `cache_mount` and `cache_seed` have
no production caller or Gateway admission. Live workspace provisioning still
uses its retained cache implementation and tests until the owning Tasks root
cutover. Host cache fills remain separate; this removal does not change their
discovery or execution.

## Review parking

`tasks.park` is one direct `operation-task-park` run. Its exact host job uses
`orbit-node-<node_id>` and the matching string `node` label. It accepts only the
Gateway's frozen group, Project, workspace, VM, provider location and agent
digest. The optional UpCloud credential remains inside the shared
private-material callback and fixed provider request client.

The native action reads current eligibility before each new stop and after
confirmed stopped power. An accepted stop settles before cancellation is
acknowledged; UpCloud observes the soft stop for 150 seconds before its existing
hard-stop action, then allows 120 seconds to settle. Incus uses the pinned local
sudo command and fresh typed `incus.vm-state` observations. Missing, unsettled,
foreign or still-running power never produces stopped proof. A lost reply is
reconciled with observation rather than trusted as success.

The Gateway projects the native stopped result under the existing Task group
execution lock. Admission records intent without changing VM state or topology.
Success records `parked`, stopped proof and `waiting_for_review` only for a
still-eligible, exactly owned workspace. Cancellation preserves VM state. A
bounded failure retains the prior board status and leaves stop proof unknown.
The removed PHP stop executor and internal `task_vm.park` and
`task_vm.confirm_stopped` registrations have no alternate dispatcher.

Image cleanup removes every server with this attempt's exact title, hostname and
`orbit-task-image` stamp, including duplicates left by lost create replies.
Other servers remain untouched. Templates carry the same stamp label; promotion,
discard and pruning refuse missing or mismatched ownership. Templatize accepts
only a title in the provider API, so the exact returned template UUID is
labelled before it can be consumed. Promotion retains that label.

Before templatizing, `image.clean` runs cloud-init cleanup with logs and removes
all SSH host keys. Its verification refuses publication if an SSH host key or
cloud-init instance remains.
