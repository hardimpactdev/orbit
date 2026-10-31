# Process and Schedule Operations

This family executes `process.create`, `process.update`, `process.start`,
`process.stop`, `process.restart`, `process.destroy`, `schedule.create`,
`schedule.enable`, `schedule.run`, and `schedule.destroy`. Direct controls are
described below. The remaining polled kinds use the following contract;
`process.start` is removed from their discovery, registration and executor.

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

Polled Vite preparation suspends traffic, writes its Instance environment, and
starts `route.caddy_converge` as a child on its Node. The Gateway completion
contract requires that child to succeed. The route family owns Caddy rendering
and reload. Its unconverted restart verifies an owned listener; direct start
owns the same-root awake step below.

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
live verification. Direct `process.stop`, `process.destroy`, `schedule.enable`,
`schedule.run` and `schedule.destroy` are absent from that registration and
scheduled discovery; the executor refuses them before any claim or command.
Their Gateway declarations require direct execution and refuse an explicit
polled writer.

## Direct Schedule controls

`operation-schedule-control` handles `schedule.enable`, `schedule.run` and
standalone `schedule.destroy` through the shared direct Operation boundary. It
reads the Gateway-admitted input after root-run binding, then runs the fixed
local agent command with `sudo -n` as the configured Node user. It verifies the
admitted agent digest, root ownership, regular file and safe permissions first.
Native command deadlines are15/45/60seconds and output is capped at16KiB. Only a
typed ready result or error code reaches Gateway projection. No root socket or
broker is used. Node209 uses `swamp209-migration-a`; other targets are exactly
`orbit-node-<admitted node_id>` with matching Node labels.

`schedule.enable`, `schedule.run` and `schedule.destroy` require direct
execution. Enable's owning Gateway slice removes PHP activation and its polled
contract. Create and Instance definition installation retain their polled paths
until their own complete cutovers.

## Direct Process controls

`operation-process-control` handles standalone start/stop/restart/destroy for
systemd or Docker Processes. Start adds the Vite DAG below; the other controls
use their single local action when no traffic preparation is needed. It shares
the family's admitted-digest and bounded sudo invocation with Schedule controls.
The Node action checks an admitted selected release when required, performs one
control/removal and reads status. Only ready/state or an error code reaches the
Gateway; no readiness relay, child Operation or keyed receipt is used. Exact
Node placement and native behavior locking own dispatch and concurrency.

The paired `process.stop` cutover switches its Gateway writer and removes the
PHP stop action, polled descriptors and receipt tests after an actual safe
action canary. Stopping a Vite Process needs no traffic preparation or private
input. Removal keeps its current writer until its owning cutover. Rollback is a
revert PR.

Native destroy also accepts the admitted Vite environment identity. It requires
the exact `/etc/orbit/vite/app-instance-<Instance ID>.env` path and forwards it
to the existing local removal primitive, which checks ownership and symlinks.
Vite start uses the same-root preparation and publication DAG below. Vite
restart uses the same-root restart DAG below.

Every Process kind targets `orbit-node-<admitted node_id>` with its matching
Node label, including Node209. Its exact worker is enrolled and independently
proven. The temporary migration-worker placement is removed. Existing migration
workers remain available to their other owners; this workflow has no fallback.

Every Schedule control targets `orbit-node-<admitted node_id>` with its matching
Node label, including Node209. The exact209worker has independent native
placement and sudo proof. The temporary migration-worker exception is removed in
the owning enable slice. No fallback or worker/grant change is required.

A local agent that fails its admitted build or ownership preflight returns
`operation.agent_invalid` before sudo starts. The native action projects that
bounded refusal as a failed Operation. Errors after the command starts keep
their existing uncertainty behavior. The workflow never replaces an admitted
digest with the current binary digest.

## Same-root Process start

Process start prepares its admitted release and Vite traffic on the exact Node.
For Vite, the next DAG step calls `@nckrtl/route-publication` on the canonical
`route-publication-node-ID` behavior with the same Operation, kind, Node and
root run ID. That method acquires the native publication lock before reading
fresh public Caddy data from the bound parent. It does not project the parent.
The final Process step starts the runtime, checks the owned listener, marks
explicit traffic awake and projects bounded status. Non-Vite start skips the
publication step. No child Operation, child run, Gateway primitive or family
relay is added. Native dispatch and behavior locking use defaults. The public
branch record keeps one version for one day; it contains no command or effect
receipt.

## Same-root Process restart

Process restart uses the same native DAG as start. Vite preparation checks the
selected release, suspends traffic, stops the admitted Process and writes its
environment before canonical Caddy publication. Activation starts that runtime,
checks its owned listener and returns bounded status without setting an awake
marker. Non-Vite restart invokes its local restart primitive. The kind leaves
polled discovery, registration and receipt policy in its owning cutover. Exact
Node placement and the native root identity are unchanged; the Gateway retains
only its existing executor recovery exception.

## Native Process installation steps

`installProcess(input, privateMaterial, signal)` in `process-installation.ts`
installs one admitted systemd or Docker Process on the current Node. A parent
native DAG can compose it after its traffic preparation and publication steps.
The action starts no child Operation and projects no parent state.

Safe input binds the Process ID, runtime artifact, desired state, agent digest
and definition digest. The shared private callback supplies either rendered unit
content or the complete container definition. The action verifies the admitted
content/spec digest before cleanup or installation. It removes only the admitted
retired artifact, stages and conditionally activates the systemd unit, or
converges the container, then reads bounded runtime status. Private bytes stay
inside the callback and sudo stdin. Activation recovery stays in the local
primitive. A stopped production clone remains stopped.

Local installation deadlines match the agent commands: staging uses 60 seconds,
activation uses 600 seconds and container convergence uses 900 seconds.

This export alone changes no writer. Each owning kind must include its direct
Gateway switch and obsolete execution deletion, required private-material
review, and live action canary before claiming completion.

`process.create` uses this installation inside the Process native root after
Vite preparation and same-root Caddy publication. Its owning Gateway change
selects direct, removes ordinary admission locking and the old claim/run and
receipt proof. Legacy discovery and registration no longer include create. The
final step verifies Vite listener ownership when desired running; it does not
set an awake marker. Private bytes never enter native inputs or history.

The `create-admitted-input.json` fixture comes from Orbit's real first-visit
HTTP admission and public direct-input response. Its test normalizes only record
IDs, artifact names and hashes, then requires exact shape equality. The native
test passes those bytes through the shipped `Creation` schema and retains null
refusal.
