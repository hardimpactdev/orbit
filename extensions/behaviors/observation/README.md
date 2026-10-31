# Native observation

Doctor admission records stored expectations and starts
`operation-doctor-inspect`. Its native DAG reads bound intent, fans out to exact
`orbit-node-N` workers, and combines their bounded reports on the root Node.
Worker failures become unverifiable findings and never clear an earlier
observation scope. Parked and unmanaged Nodes need no placed inspection. Doctor
uses observer primitives only; SQL reads use the native database clients. It
never calls an agent action.

The Gateway retains authorization, safe intent, fingerprints and cursors.
Schedule commands and existing database credentials use the shared private
material boundary, selected by admitted participant and owning Node. They stay
inside each private callback. Reports contain no command, credential, raw
configuration or raw exception message. The log collector reads on the Gateway
worker and returns only exception class, normalized application frame, request
identity, timestamp and cursor. The Gateway commits those signals and cursor
together, refusing a stale cursor update.

Scheduled collection and independent Horizon checks run every ten minutes.
Gateway database housekeeping runs every five minutes and retention runs daily
at 02:17 UTC. Their staggered schedules retain one concurrent scheduler run;
there is no minute poller or Gateway execution queue. Collection admits one
native Operation and does not wait inside the scheduler. Filing remains
separate.

Run the focused Deno tests and `native_test.py`. Native validation preserves the
production workflow's exact Node placement. A live dev proof must also record
the Operation, root run, first bound read and placed inspection outcomes.
