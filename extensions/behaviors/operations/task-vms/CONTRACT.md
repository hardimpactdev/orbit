# Task VM Operations

`task_vm.image_build` directly starts `operation-task-vm-image-build` (UUID
`878a384f-8b8a-4066-bd17-dcad8eac3e86`). One root places admission and image
steps at the exact admitted build host, and publishes the pinned public Pi
artifact from the admitted Gateway worker as native file data. Recipe, smoke,
seed finalization, promotion and pruning are native steps. It has no claim,
lease, keyed receipt, child Operation, Gateway observation loop or polling
registration. The Gateway projects bounded phase/fingerprint results and the
terminal build state. Rollback is a revert of the paired cutover commits. The
former image helper kinds, including `task_vm.image_finish_seed`, have no
independent caller or registration: seed finalization is a step of this root.
The retained paths below apply to other Task VM kinds, including UpCloud images.

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

Incus image parents drive reset, launch, boot, six native recipe phases,
preparation, cleanup, publication, smoke, topology seed, promotion and pruning.
Preparation and publication evidence belongs to stamped child Operations.
Promotion verifies that the smoke VM booted the candidate fingerprint. Re-entry
reuses phase boot/invocation identities. Only a later explicit attempt restarts
a proven failed invocation.

UpCloud image parents use the same recipe, owned labelled servers and a private
unverified template. Builder verification, firewall, cleanup, template creation
and smoke checks require child proof before promotion. Pruning retains the
newest two verified templates and templates pinned by allocations. Swamp owns
all provider polling, graceful stop escalation and deletion waits; a Gateway
UpCloud primitive submits one fixed API action.

Each host sweep is independent. The Gateway supplies owned orphan candidates and
reports strays. Deletion rechecks ownership. Two distinct sweep Operations must
miss a VM before its row fails; a replay of one observation cannot count twice.

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

The retained UpCloud image observation loop yields on its last admitted child
until the owning UpCloud parent cutover. Incus images have no such loop.

The unused cache-preparation Operation family is retired:
`prepare_package_cache`, `cache_initialize`, `cache_mount` and `cache_seed` have
no production caller or Gateway admission. Live workspace provisioning still
uses its retained cache implementation and tests until the owning Tasks root
cutover. Host cache fills remain separate; this removal does not change their
discovery or execution.

Host cache fill keeps one 1,700-second deadline from the original native run
start. The canonical pinned agent validates non-null lockfile checksums against
archive bytes, including cached hits. A failed fill job triggers a placed native
failure-projection job on the same host. It reports only a fixed failure code
and no unverified archive inventory, so a lost agent reply cannot leave an open
Operation blocking the next normal fill admission. Worker recovery remains
native; there is no Gateway failure poll or retry loop.
