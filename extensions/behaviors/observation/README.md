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

Observation is a read and Gateway-state schedule family. It declares no
executing Operation kind. Direct execution registration, private material and
Node-effect workflows belong to the families that perform those writes.
`contract.ts` defines this behavior's configuration, context and safe response
fields without importing the removed Operation runner. `collection.ts` types the
selected resource plan. Every family API requires the configured control-plane
peer with Gateway access; per-Node worker enrollment grants no fleet authority.

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
