# `orbit update`

[Back to Operation commands.](../README.md)

Update the local Orbit installation on the machine where the command is invoked.

This command is the local update path. In production artifact installs it
updates the native Orbit CLI binary and runs gateway runtime/dependency/
migration steps when the local installation is a gateway-context install. In
source-mounted Docker and Incus development/E2E topologies it keeps
`/usr/local/bin/orbit` pointed at `<source>/apps/cli/orbit` and updates by
changing the mounted source. It does not update other nodes and does not repair
fleet drift.

## Usage

```bash
orbit update [--json]
```

## Examples

```bash
orbit update
orbit update --json
```

## Arguments and options

- `--json`: Output JSON.

## What Happens

`update` runs the local Orbit update sequence:

1. For production installs, download the prebuilt Orbit CLI binary for this
   host OS/arch from the configured release source (default: GitHub Releases,
   overridable with `ORBIT_BINARY_URL`). Source-mounted Docker/Incus
   development and E2E lanes keep `/usr/local/bin/orbit` pointed at
   `<source>/apps/cli/orbit` and update by changing the mounted source.
2. Keep the host `orbit` launcher pointed at the correct local entry point
   (updated binary artifact in production, mounted source entry point in
   source-mounted lanes).
3. Verify the resolved local Orbit entry point responds to `--version`.
4. Install gateway Composer dependencies inside gateway `orbit-runtime`.
5. Run Orbit database migrations for the gateway inside gateway `orbit-runtime`.
6. Report the local update result.

The command affects only the current Orbit installation. On a gateway host,
local migrations may change the gateway database schema as part of the normal
Laravel migration path, but `update` does not create or modify fleet
configuration.

Use [`update:all`](../2_update-all/update-all.md) when the operator needs to
roll out the same Orbit update across the fleet.

## Output

Run `orbit update` to see live progress and a final success or failure result.

Human output reports whether the local installation updated successfully. A
failed step remains visible with captured download, `orbit-runtime` Composer,
or migration output.

Use `--json` for the machine-readable local update result and any failure
metadata.

## Requirements

- The Orbit install root is writable (`ORBIT_INSTALL_PATH` or `$HOME/orbit`).
- Production artifact installs require a reachable release source (GitHub
  Releases by default, or the `ORBIT_BINARY_URL` override for offline and E2E
  artifact scenarios) plus permission to write the binary and update the host
  launcher link.
- Gateway runtime update steps require Docker and gateway `orbit-runtime` for
  dependency installation and migrations.
- Source-mounted Docker/Incus development and E2E lanes require access to the
  mounted checkout and keep `/usr/local/bin/orbit` pointed at
  `<source>/apps/cli/orbit`.

## Related Commands

Use these commands before or after running `orbit update`.

- [`update:all`](../2_update-all/update-all.md) - update the local installation and
  every managed Orbit installation
- `doctor` - verify drift after updates once the doctor command contract is
  converted

## Technical Contract

See [`update` technical contract](technical/1_update.md).
