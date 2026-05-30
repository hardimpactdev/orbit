# `orbit update`

[Back to Operation commands.](../README.md)

Update the Orbit CLI binary and the gateway on the machine where the command is invoked.

This command is the local update path. It downloads the prebuilt Orbit CLI
binary for this host OS/arch, relinks the host `orbit` launcher, installs
gateway Composer dependencies inside `orbit-runtime`, and applies local Orbit
migrations. It does not update other nodes and does not repair fleet drift.

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

1. Download the prebuilt Orbit CLI binary for this host OS/arch from the
   configured release source (default: GitHub Releases, overridable with
   `ORBIT_BINARY_URL`).
2. Relink the host `orbit` launcher to the updated binary (default:
   `/usr/local/bin/orbit`, overridable with `ORBIT_BIN_PATH`).
3. Verify the updated binary responds to `--version`.
4. Install gateway Composer dependencies inside `orbit-runtime`.
5. Run Orbit database migrations for the gateway inside `orbit-runtime`.
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
- The configured release source is reachable (GitHub Releases by default, or
  the `ORBIT_BINARY_URL` override for offline and E2E scenarios).
- Docker and the `orbit-runtime` runtime are available for dependency
  installation and migrations.

## Related Commands

Use these commands before or after running `orbit update`.

- [`update:all`](../2_update-all/update-all.md) - update the local installation and
  every managed Orbit installation
- `doctor` - verify drift after updates once the doctor command contract is
  converted

## Technical Contract

See [`update` technical contract](technical/1_update.md).
