# Native Process runtime composition

`processSystem(run?, sleep?)(name, input, signal?, beforeContainerReplacement?)`
executes fixed system commands on the current Node. The parent owns placement,
private-material access and final projection. It admits no child Operation and
invokes no agent action.

The optional replacement callback composes initialization inside the same root.
The runtime checks ownership, expected specification and recovery state first.
An already-installed requested specification skips initialization and returns
its normal health result. Otherwise it calls initialization before creating the
replacement. A typed callback refusal returns only its error code and preserves
the prior canonical container.

`process.remove` accepts optional `anonymous_volumes: true` for owned temporary
containers. It validates the entire owned artifact set before removing any
container or volume. The default leaves volumes unchanged.

`installProcessLocal(input, material, transport, signal?)` reuses this runtime
and the canonical installer with in-memory private material. It returns bounded
ready/state or error_code data. Uncertain effects throw a fixed diagnostic.
Systemd environments use root-owned 0600 files; Docker definitions use stdin to
the fixed Docker socket. No Schedule component is required.

Process file writes use a root-owned temporary file, private stdin to `dd`, mode
checks and an atomic rename. The real filesystem regression exercises this path
through a Deno pipe and passwordless sudo.

`installProcessWithTransport(input, privateMaterial, transport, signal?)`
accepts an existing parent's `withPrivateMaterial` callback directly. It uses
the same installer and transport adapter as `installProcessLocal`. Existing
Process create/control/start behavior defaults also call the native Process/Vite
runtime.

Removal accepts leftover `.orbit-process-*` files only inside the selected
Process private generation directory and only with the same root ownership, 0600
mode and regular-file checks. It validates the whole set before effects.

## Instance member steps

`instanceActions()` composes the canonical local Process and Schedule actions
inside an existing native root. `instanceRuntime()` also exposes the dedicated
restart and definition-installation DAG methods. The Gateway captures owned
members and only projects bounded native member outcomes. No member Operation or
child admission is created.

Restart selection includes only active, desired-running Processes with
`restart_on_deploy`. Definition copies retain stopped Process intent and
disabled timers. The captured member list is immutable. Private definitions are
selected from the root callback and stay inside each local action. Vite
preparation runs before same-root Caddy publication and activation.
