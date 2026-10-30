# Process and Schedule Operations

This family executes `process.create`, `process.update`, `process.start`,
`process.stop`, `process.restart`, `process.destroy`, `schedule.create`,
`schedule.enable`, `schedule.run`, and `schedule.destroy`.

The Gateway admits the target and complete specification. It supplies references
for unit content, Docker commands and environments, and Schedule commands. Swamp
passes those references back unchanged. Kit v2 resolves them inside the Gateway.
No command text or environment value belongs in a Swamp step or completion.

A systemd create stages the owned unit and activates a changed or pending unit.
An unchanged unit in its desired state needs no activation. Docker convergence
uses the admitted container definition. Runtime switches remove the recorded
retired artifact first. Kit v2 input bindings bind that cleanup to its exact
runtime and name.

The Process completion contract requires the final Gateway readiness check. That
check proves the earlier agent receipts, observed runtime status, and any Vite
Caddy child before the Gateway projects lifecycle state. Swamp executes the
effects and waits for children. Schedule removal keeps its record while a
command is still running. A production clone passes stopped Process intent and
disabled Schedule intent unchanged.

Vite preparation suspends traffic, writes its Instance environment, and starts
`route.caddy_converge` as a child on its Node. The Gateway completion contract
requires that child to succeed. The route family owns Caddy rendering and
reload. Startup verifies an owned listener before an explicit start marks
traffic awake.

Run the focused checks from the repository root:

```sh
~/.swamp/deno/deno fmt --check extensions/behaviors/operations/processes-schedules
~/.swamp/deno/deno lint extensions/behaviors/operations/processes-schedules
~/.swamp/deno/deno test --allow-read=. extensions/behaviors/operations/processes-schedules/mod_test.ts
python3 -B -m unittest discover -s extensions/behaviors/operations/processes-schedules -p native_test.py -v
```

The native tests use the pinned Swamp CLI in a disposable repository. They run
the parent, dispatch and family workflows, then resume the family in a new
process after losing a command or completion reply. Their fake Gateway retains
keyed receipts. Live proof follows the paired Gateway and Swamp deployment and
uses a throwaway development Process and Schedule. It must never stop a
production Process.

Every remote effect reads its keyed Gateway receipt before `/run`. A terminal
receipt supplies the original result without another invocation. Running or
unavailable receipts stay pending. An unknown receipt after a saved effect
intent also stays pending. Swamp never repeats an uncertain Process restart or
Schedule run. Protected values and receipt sanitization stay in the shared
Gateway and agent helpers.

Instance runtime composition kinds are `process.install_instance_definitions`,
`schedule.install_instance_definitions` and `process.restart_instance`. Public
input is exactly `{instance_id}`. The Gateway owns the captured plan and derives
child targets; Swamp sends only stable `runtime-N` keys, waits through kit B,
then invokes the Gateway readiness descriptor. Restart success carries the exact
verified `process_ids` list. Its registration remains gated on kit C.

The placed family claims its own kind, persists an assignment before effects,
and keeps retries bound to that Operation. Native tests remove placement labels
only inside their disposable local repository. They prove DAG and receipt
recovery, while deployed worker placement needs the live verification.

## Direct Schedule controls

`operation-schedule-control` handles `schedule.enable`, `schedule.run` and
standalone `schedule.destroy` through the shared direct Operation boundary. It
reads the Gateway-admitted input after root-run binding, then runs the fixed
local agent command with `sudo -n` as the configured Node user. It verifies the
admitted agent digest, root ownership, regular file and safe permissions first.
Native command deadlines are15/45/60seconds and output is capped at16KiB. Only a
typed ready result or error code reaches Gateway projection. No root socket or
broker is used. Placement stays exactly `orbit-node-<admitted node_id>`.

Each writer defaults to polled until actual dev/canary action proof. Instance
removal child Schedule Operations retain the old writer until their parent
moves. The earlier209pre-cancelled canaries proved binding/read/projection only;
their temporary migration placement was restored live in main e830a3d.
Production success must precede deletion of the replaced Gateway executor.
