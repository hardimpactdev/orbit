# `orbit version`

[Back to Operation commands.](../README.md)

Show the installed Orbit CLI version, release timestamp, install timestamp,
and whether a newer Orbit release is available.

This command is local-only and read-only. It does not contact the gateway or
mutate fleet configuration.

## Usage

Use this form:

```bash
orbit version [--json]
```

The root `orbit --version` and `orbit -V` forms render the same output.

## Examples

```bash
orbit version
orbit --version
orbit --version --json
```

## Arguments and options

- `--json`: Output JSON.

## What Happens

`version` reads the installed CLI version, checks the public
`hardimpactdev/orbit` release manifest assets on a best-effort basis, and reads
local install metadata from the operator host. The GitHub Releases API is only a
fallback when public manifest metadata is missing.

Use it when you need to confirm the installed Orbit version before or after an
update.

The installed timestamp comes from `ORBIT_INSTALL_METADATA_PATH` when set, or
`$HOME/.config/orbit/install.json` by default. The installer and local updater
write this metadata after the linked CLI binary verifies. Older installs fall
back to the linked binary mtime when no matching metadata exists.

Release lookups are non-fatal. If public release manifests and GitHub Releases
metadata are unreachable, the command still exits successfully and renders
unknown release metadata.

## Output

Run `orbit version` to see aligned rows for quick terminal scanning:

```text
Version       0.1.105
Released at   17-06-2026 - 12:47
Installed at  17-06-2026 - 12:54
```

When a newer release exists, the version row includes it:

```text
Version       0.1.105 (new version available: 0.1.108)
Released at   17-06-2026 - 12:47
Installed at  17-06-2026 - 12:54
```

Use `--json` for the same metadata in the standard machine-readable envelope.

## Related Commands

Use these commands before or after checking the installed version.

- [`update`](../1_update/update.md) - update the local Orbit installation
- [`update:all`](../2_update-all/update-all.md) - update the local installation and
  every managed Orbit installation

## Technical Contract

See [`version` technical contract](technical/1_version.md).
