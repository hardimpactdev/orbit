# Tool Catalog: `orbstack`

[Back to tool catalog.](README.md)

## Catalog

These fields describe the OrbStack tool's identity, backend, and support model in
Orbit.

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
provider. Orbit can install, update, probe, and safely adopt OrbStack through
the generic `tool:*` surface and `doctor --family=tool`. The tool does not
declare credentials, reconfigure, or remove capabilities in this slice.

`orbstack` does not create a process row, declare `relatedProcess()`, or expose
lifecycle or log commands. OrbStack start, stop, restart, and Docker lifecycle
operations remain outside Orbit ownership until a future product decision admits
them.

## Install and Update

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

Install and update scripts do not run `orb start`, `orb stop`, or
`orb restart docker`.

## Probe

Orbit probes OrbStack through:

- `binary`: `/usr/local/bin/orb`
- `version_command`: `/usr/local/bin/orb version`
- presence checks for `/usr/local/bin/orbctl` and `/Applications/OrbStack.app`

`orb status` state parsing is deferred. Probe metadata does not include
`service`, `container`, or lifecycle repair commands, and log commands are
unsupported for this tool.

## Doctor Relationship

`doctor --family=tool` reports OrbStack capability drift on supported macOS
nodes. Runtime state inside OrbStack remains owned by OrbStack until a future
external runtime/process model lands.

## Scope Boundaries

`orbstack` must not:

- Create or manage an Orbit process row.
- Expose generic tool lifecycle restart/control commands or `orbstack:*`
  lifecycle commands in this slice.
- Run lifecycle-mutating OrbStack commands during install, update, probe, or
  doctor repair flows.