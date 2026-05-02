# Process Commands

Process commands manage app-owned runtime intent. A process definition belongs
to one app, is stored on the gateway, and is inherited by every workspace for
that app.

The gateway is the source of truth for process intent. When node-side work is
required, the gateway renders and enacts derived runtime units on the owning app
node.

## Domain Rules

- The gateway owns process intent.
- Process names are identity slugs: lowercase letters, digits, and hyphens only;
  they cannot start or end with a hyphen and are limited to 64 characters.
- Runtime units are physical artifacts derived from app, optional workspace, and
  process intent. They are not the product model.
- Each process definition renders one runtime unit for the main app instance and
  one runtime unit for each workspace of that app.
- Runtime unit filenames use
  `orbit_<app>_<workspace|main>_<process>.service`. The `orbit_` prefix marks
  Orbit ownership; underscores are reserved as backend segment delimiters and
  are not allowed in identity slugs.
- Restart policy is process intent. Each derived main-app or workspace runtime
  unit uses the process definition's `never`, `on_failure`, or `always` policy.
  Manual `process:restart` actions do not change that policy.
- Process definitions are edited at the app level, not per workspace.
- Process definitions may opt in to crash notification. When enabled, a
  `crashed` process event resolves the effective agent IDE for the app or
  workspace and notifies the active session when one is available.
- Crash events come from a narrow internal app-node-to-gateway intake path
  emitted by Orbit-managed runtime hooks. That intake path is not a CLI command
  contract.
- Process lifecycle events are durable history, not process-unit intent. Orbit
  records `started`, `stopped`, and `crashed` events so SSE consumers, CLI
  streams, and automation can react without storing runtime units as state rows.
- Default process read commands report gateway intent plus latest durable
  process events. They do not synchronously SSH to app nodes or run live
  `systemctl` probes. Live runtime verification belongs to
  [`doctor --family=process`](process-doctor.md); live event delivery belongs
  to the internal event stream.
- Runtime lifecycle commands start, stop, restart, and inspect derived units.
- Logs come from the node runtime backend.
- Create commands may use positional arguments for required identity or payload
  fields. Edit commands use named options for editable fields so omitted fields
  can mean "preserve the current value." This is why `process:add` accepts the
  required `[command]` positionally, while `process:edit` uses
  `--command=<command>` as one optional edit field among several.

## Process Caller Role Rule

Process commands use gateway-owned access policy for visibility,
authorization, and runtime operations. Caller role is resolved before prompts
or command side effects.

- Control and gateway callers may run process read, runtime-lifecycle, and
  intent-mutation commands when authorized.
- App-node callers may run `process:list`, `process:logs`, `process:start`,
  `process:stop`, and `process:restart` when authorized for the resolved app or
  workspace context.
- App-node callers may not run app-owned process intent mutation commands:
  `process:add`, `process:edit`, or `process:remove`.
- Unknown caller roles are denied before prompts or side effects.
- Local app-node context may resolve app or workspace defaults, but it is not
  authorization.
- Allowed app-node process commands still call the gateway typed API. The
  app-node CLI never writes process intent, reads journald directly, or operates
  systemd directly.

## Runtime Unit Environment

Derived process runtime units expose a runtime environment that is separate from
workspace setup and teardown step environment. Runtime units do not receive
`ORBIT_*` lifecycle variables by contract.

| Variable | Value | Why it is exposed |
| --- | --- | --- |
| `PATH` | Predictable command lookup path including user-local tool directories | Lets commands resolve tools such as `vp`, `bun`, and project-local binaries under systemd. |
| `HOME` | Runtime user's home directory | Lets tools find home-relative config and caches. |
| `APP_URL` | Resolved app or workspace HTTPS URL | Gives Laravel/runtime code the canonical public URL. |
| `VITE_APP_URL` | Resolved app or workspace HTTPS URL | Keeps Vite-aware processes aligned with the runtime URL. |
| `VITE_DEV_SERVER_KEY` | Orbit-managed TLS key path for the resolved URL | Lets dev-server processes serve HTTPS with Orbit-managed cert material. |
| `VITE_DEV_SERVER_CERT` | Orbit-managed TLS cert path for the resolved URL | Lets dev-server processes serve HTTPS with Orbit-managed cert material. |

`VITE_VALET_HOST` is intentionally not part of long-running process runtime
units. It belongs to app/workspace setup compatibility paths, while process
units receive Orbit-owned URL and certificate fields that are stable across
frontend toolchains.

## Development Server Runtime

Process commands store the operator-provided command and do not rewrite it for a
specific frontend server. A development server that must be reachable from a
control node browser or support HMR across the Orbit network must bind to a
node-reachable interface instead of loopback. For Vite-backed processes, the
expected command shape is:

```text
npm run dev -- --host=0.0.0.0
```

Equivalent package-manager or framework adapter commands are valid when they
produce the same non-loopback bind behavior. Orbit supplies `APP_URL`,
`VITE_APP_URL`, `VITE_DEV_SERVER_KEY`, and `VITE_DEV_SERVER_CERT` so the process
can serve the app/workspace URL over Orbit-managed HTTPS and keep browser HMR
connected through the network path.

Firewall permissions, proxy routes, DNS names, and TLS trust remain owned by
their respective families. The process family owns the stored command, runtime
unit environment, and process lifecycle, not public exposure policy.

## Commands

1. [`orbit process:add [name] [command]`](1_process-add/process-add.md)
2. [`orbit process:edit [name]`](2_process-edit/process-edit.md)
3. [`orbit process:remove [name]`](3_process-remove/process-remove.md)
4. [`orbit process:list`](4_process-list/process-list.md)
5. [`orbit process:start [name]`](5_process-start/process-start.md)
6. [`orbit process:stop [name]`](6_process-stop/process-stop.md)
7. [`orbit process:restart [name]`](7_process-restart/process-restart.md)
8. [`orbit process:logs [name]`](8_process-logs/process-logs.md)

## Doctor

[`process-doctor.md`](process-doctor.md) defines the `process` family probe,
issue codes, fix map, adopt map, and test mapping for
`doctor --family=process`.

## Internal Commands

This command supports internal Orbit machinery and is hidden from the public
command list.

1. [`orbit process-event:stream`](internal/1_process-event-stream/process-event-stream.md)
