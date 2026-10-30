# Direct database cleanup

The Gateway records one `database.sqlite_cleanup` intent and starts
`operation-database-cleanup` directly. Its per-kind switch defaults to the
previous Swamp path. Existing intents retain their writer when the switch
changes.

The workflow uses a native Node behavior lock. It invokes only the fixed cleanup
primitive admitted by the Gateway, then projects the bounded result. It never
claims work, heartbeats, creates child Operations, saves keyed effect receipts
or waits in PHP. Native Swamp recovery can repeat cleanup after a lost reply;
cleanup must converge an already absent snapshot.

The Gateway shared direct boundary supplies
`GET /api/v1/direct-operations/{operation}?run_id=UUID`, `POST .../primitive`
and `POST .../result`. The primitive receives only `run_id`; it cannot select a
command, endpoint, Node or input. Families add no controller or token transport.

Load this source before deploying the dormant Gateway branch. The orchestrator
extends the one shared `ORBIT_SWAMP_RUN_TOKEN` grant to this workflow. This
source creates no token or grant. The shared helper and the Databases
intent/result handler must be deployed before this workflow is enabled.

Prove both clone-directory and seed-snapshot cleanup on a disposable development
target before selecting direct. Keep the previous workflow and Gateway adapters
until that proof and the required production success. Record run IDs, latency
and Gateway line changes with the takeover slice. This source alone is not
takeover.
