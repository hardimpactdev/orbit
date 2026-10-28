# Observation schedules

Swamp schedules collection every ten minutes, independent Horizon health every
ten minutes, Gateway housekeeping every five minutes and retention daily at
02:17 UTC. The seconds differ so these routines do not start together. Each
workflow allows one concurrent job. Horizon health uses a separate behavior from
fleet collection, so a slow or unavailable fleet cannot delay it.

Doctor reads stay in Orbit. The Gateway selects resource groups from stored
intent. Swamp observes every selected Node before requesting the resource
groups, then collects signals. It saves progress between reads. A restart
continues the unfinished collection before admitting another pass. Replaying one
completed collection does not repeat reads. A lost reply retries its exact
collection and group; the Gateway owns read receipts and fingerprint updates.

Collection does not file problems. The existing hourly filing policy remains
separate. No routine gains a secret reference or a multi-Node write child. The
schedule is not a remote program or an unrestricted Artisan endpoint.

This family uses Kit v2 E. It introduces no executing Operation kind: the secret
references, child Operations, run allowlists and completion variants are empty.
Shared execution primitives remain owned by lane 0d. Doctor's normal API and
typed probes remain reads. `contract.ts` closes the response fields to status
and integer counts. `collection.ts` types the selected resource plan. The
existing executor request authorizer restricts every family API to the
configured Services peer with Gateway access.

Local proof runs the focused Deno tests and the native Python test. The latter
kills a real Swamp process after a Node read receipt, runs `run doctor --fix`,
then `workflow recover` and `workflow resume` in new processes. The original
collection and read receipts are reused. CI checks types, lint and formatting.

After the paired deployment, run `observation-collection` against the designated
dev fleet and inspect its Swamp run and collection receipt. Doctor remains a
read, so this proof has a Swamp run ID and collection ID rather than a new
Operation ID. Verify the independent `observation-horizon` run and the next
five-minute housekeeping receipt. The orchestrator owns deployment and target
selection.
