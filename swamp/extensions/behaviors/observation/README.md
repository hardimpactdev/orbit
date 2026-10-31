# Native observation

Doctor admission records stored expectations and starts
`operation-doctor-inspect`. Its native DAG reads bound intent, fans out to exact
`orbit-node-N` workers, and combines their bounded reports on the root Node. The
prepare step publishes its Node list as a native resource before fan-out
planning. Each inspection publishes its bounded report as a native resource
before aggregation. Plain method return values do not populate cross-job step
outputs.

Worker failures become unverifiable findings and never clear an earlier
observation scope. Parked and unmanaged Nodes need no placed inspection. Doctor
runs native domain reads directly, including SQL through native database
clients. Continuous Node and Process health comes from fresh Gateway
observations. It never launches orbit-agent commands.

The Gateway retains authorization, safe intent, fingerprints and cursors.
Schedule commands and existing database credentials use the shared private
material boundary, selected by admitted participant and owning Node. They stay
inside each private callback. Reports contain no command, credential, raw
configuration or raw exception message. The log collector reads on the Gateway
worker and returns only exception class, normalized application frame, request
identity, timestamp and cursor. Swamp also classifies redacted Activity rows and
assistance requests, preserving first-run history suppression, source cursors
and bounded samples. The Gateway commits selected fingerprints and cursors
together, refusing stale cursor updates.

Scheduled collection and independent Horizon checks run every ten minutes.
Maintenance admission runs every five minutes and retention runs daily at 02:17
UTC. Admission steps run on the control worker on Node 209. Each admits a native
Operation whose execution runs on the exact Gateway worker.

Swamp selects interrupted Activities and expired batch/failed-job records from
safe Gateway row metadata. Gateway projection applies only those selected state
changes. Activities already settled stay unchanged. Horizon snapshots run in
Swamp through the existing Redis login's shared private-material field,
including atomic counter resets, snapshot trimming and supervisor-based queue
waits. Gateway keeps no maintenance lock or cached receipt. A failed snapshot
still projects the independent Activity work. Native defaults own concurrency
and resumption; there is no minute poller or Gateway execution queue.

Run the focused Deno tests and `native_test.py`. Native validation preserves the
production workflow's exact Node placement. A live dev proof must also record
the Operation, root run, first bound read and placed inspection outcomes.
