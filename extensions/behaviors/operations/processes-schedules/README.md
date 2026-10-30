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

The polled family claims only kinds listed in `registration.json`, persists an
assignment before effects, and keeps retries bound to that Operation. Native
tests remove placement labels only inside their disposable local repository.
They prove DAG and receipt recovery, while deployed worker placement needs the
live verification. Proven `process.stop`, `schedule.run` and `schedule.destroy`
are absent from that registration and scheduled discovery; the executor refuses
them before any claim or command. Their Gateway declarations require direct
execution and refuse an explicit polled writer.

## Direct Schedule controls

`operation-schedule-control` handles `schedule.enable`, `schedule.run` and
standalone `schedule.destroy` through the shared direct Operation boundary. It
reads the Gateway-admitted input after root-run binding, then runs the fixed
local agent command with `sudo -n` as the configured Node user. It verifies the
admitted agent digest, root ownership, regular file and safe permissions first.
Native command deadlines are15/45/60seconds and output is capped at16KiB. Only a
typed ready result or error code reaches Gateway projection. No root socket or
broker is used. Placement stays exactly `orbit-node-<admitted node_id>`.

`schedule.run` and `schedule.destroy` use their proven direct path only.
Unconverted Schedule kinds retain their polled path until the complete owning
cutover. The earlier209pre-cancelled canaries proved binding/read/projection
only; their temporary migration placement was restored live in main e830a3d.
Production success must precede deletion of the replaced Gateway executor.

## Direct Process controls

`operation-process-control` handles standalone start/stop/restart/destroy for
systemd or Docker Processes without Vite traffic preparation. It shares the
family's admitted-digest and bounded sudo invocation with Schedule controls. The
Node action checks an admitted selected release when required, performs one
control/removal and reads status. Only ready/state or an error code reaches the
Gateway; no readiness relay, child Operation or keyed receipt is used. Exact
Node placement and native behavior locking own dispatch and concurrency.

The paired `process.stop` cutover switches its Gateway writer and removes the
PHP stop action, polled descriptors and receipt tests after an actual safe
action canary. Stopping a Vite Process needs no traffic preparation or private
input. Other Process controls keep their current writers. Rollback is a revert
PR.

Native destroy also accepts the admitted Vite environment identity. It requires
the exact `/etc/orbit/vite/app-instance-<Instance ID>.env` path and forwards it
to the existing local removal primitive, which checks ownership and symlinks.
Vite startup remains refused until its native preparation steps are converted.

For `process.stop` and `process.destroy` canaries on admitted Node209, the
workflow chooses the existing `swamp209-migration-a` worker. Every other Node
and Process kind keeps exact `orbit-node-ID` placement. There is no fallback to
another Node. The canary owns a short-lived sleep Process and changes no serving
unit.

For the first real `schedule.run` canary, Node209uses its existing reviewed
`swamp209-migration-a` token identity, which runs as the Node user with sudo.
The workflow chooses that identity only for admitted Node209; every other Node
retains exact `orbit-node-ID` placement. This is explicit placement, with no
fallback to another Node. Remove the209exception when lane21enrolls its exact
per-Node identity. The canary owns a future-dated timer whose command is
`/usr/bin/true`; it changes no serving unit.

A local agent that fails its admitted build or ownership preflight returns
`operation.agent_invalid` before sudo starts. The native action projects that
bounded refusal as a failed Operation. Errors after the command starts keep
their existing uncertainty behavior. The workflow never replaces an admitted
digest with the current binary digest.
