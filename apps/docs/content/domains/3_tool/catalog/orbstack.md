# Tool Catalog: `orbstack`

[Back to tool catalog.](README.md)

## Catalog

These fields describe the OrbStack tool's identity, backend, and support model
in Orbit.

| Field | Value |
| --- | --- |
| Slug | `orbstack` |
| Label | OrbStack |
| Backend | macOS application and CLI (`orb`, `orbctl`) |
| Support model | Installable macOS runtime-provider capability |
| Category | `infrastructure` |
| Supported operating systems | `macos` |

## Capabilities

`orbstack` is a macOS-only managed tool for the external OrbStack runtime
provider. Orbit can install, update, probe, safely adopt, start, stop, and
restart OrbStack through the generic `tool:*` surface and
`doctor --family=tool`. The tool does not declare credentials, reconfigure, or
remove capabilities in this slice.

`orbstack` does not create a process row, declare `relatedProcess()`, or expose
logs/reload commands. The lifecycle commands are tool-owned because OrbStack is
itself an external host runtime provider, not an Orbit process row.

## Install, update, and lifecycle

Install uses the official Homebrew distribution channel documented by OrbStack:

```bash
brew install orbstack
```

Homebrew resolves `orbstack` to the published cask without requiring
`--cask`, so Orbit emits the official installer command rather than a
redundant cask flag.

Update uses the greedy Homebrew cask upgrade channel:

```bash
brew upgrade --greedy orbstack
```

Tool-owned lifecycle uses OrbStack's own CLI:

```bash
orbctl start
orbctl stop
orbctl restart
```

`orbctl stop` without arguments stops the OrbStack service, including Docker and
all machines. Orbit treats `tool:stop orbstack` and `tool:restart orbstack` as
host lifecycle mutations that operators requested explicitly; tests must fake
command execution.

## Probe

Orbit probes OrbStack through:

- `binary`: `/usr/local/bin/orb`
- `version_command`: `/usr/local/bin/orb version`
- presence checks for `/usr/local/bin/orbctl` and `/Applications/OrbStack.app`

`orb status` state parsing is deferred. Probe metadata does not include
`service`, `container`, log commands, reload commands, or process repair
commands for this tool.

## Doctor Relationship

`doctor --family=tool` reports OrbStack capability drift on supported macOS
nodes. Runtime state inside OrbStack remains owned by OrbStack. Orbit only
dispatches lifecycle when the operator explicitly runs `tool:start`,
`tool:stop`, or `tool:restart` for `orbstack`.

This operator-requested `tool:*` lifecycle does not make OrbStack the Desktop
Agent provider. Desktop never invokes `orb` or `orbctl` for Agent startup and
owns only the Colima `orbit` profile.

## Scope Boundaries

`orbstack` must not:

- Create or manage an Orbit process row.
- Expose tool log streaming or reload commands.
- Run lifecycle-mutating OrbStack commands during install, update, probe, or
  doctor repair flows.
