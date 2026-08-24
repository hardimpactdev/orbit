# `orbit update`

[Back to Operation commands.](../README.md)

Update the local Orbit installation on the machine where the command is invoked.

This command is the local update path. In production artifact installs it
updates the configured owner user's native Orbit CLI binary and user-local
launcher. In source-dev Docker and Incus development/E2E topologies it keeps
`/usr/local/bin/orbit` pointed at `<source>/apps/cli/orbit` and updates by
changing the mounted source. It does not update the gateway service, other
nodes, or repair fleet drift. Gateway service replacement belongs to
[`orbit update:all`](../2_update-all/update-all.md).

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

1. Check the configured release source for the latest available Orbit version.
   If the installed version is already current, skip with
   `Skipped: <version> is already installed`.
2. When a newer release exists, read the gateway version as a read-only gate.
   If the gateway is still behind the latest release, skip with
   `Skipped: please update your gateway first`; the local CLI is never updated
   past the gateway's version.
3. For production installs, download the prebuilt Orbit CLI binary for this
   host OS/arch from the configured release source (default: GitHub Releases,
   overridable with `ORBIT_BINARY_URL`). Source-mounted Docker/Incus
   development and E2E lanes keep `/usr/local/bin/orbit` pointed at
   `<source>/apps/cli/orbit` and update by changing the mounted source.
   Fleet updates use the same release manifest to select the matching
   platform/architecture Orbit Agent artifact for supported Agent-eligible
   Linux and macOS/Darwin nodes. `orbit update` itself still updates only the
   current host CLI installation.
4. Keep the configured owner-user `orbit` launcher pointed at the correct local
   entry point (updated binary artifact in production, mounted source entry
   point in source-mounted lanes). Production self-update does not discover or
   rewrite protected unmanaged launchers such as `/usr/local/bin/orbit`.
5. Ensure the supported zsh shell integration when the active login shell is
   zsh (including already-current installs that skip a binary download, and
   including the first upgrade from a pre-feature binary via candidate
   `orbit --version --local --json` post-replace verify) so unquoted namespace
   wildcards such as `process:*` reach Orbit literally. Failure to ensure that
   integration is reported as an update failure rather than silent success.
   Bash-only hosts skip this step without creating a new `.zshrc`. When
   `ZDOTDIR` is set, the managed source block is appended under that directory;
   the Orbit-owned snippet still lives under `~/.config/orbit/shell/`. The
   managed alias takes effect only in a newly started or freshly sourced zsh
   session; `orbit update` cannot mutate the parent shell.
6. Run `orbit doctor` in verify mode for the local node and report the issue
   count without failing an otherwise completed binary update.
7. Report the local update result.

The command affects only the current Orbit CLI installation. On a gateway host,
it updates the host CLI binary or source-dev CLI entrypoint; it does not replace
`orbit-gateway`, run gateway migrations, or mutate fleet configuration.
Orbit Agent artifact replacement is part of `update:all` for supported
Agent-eligible nodes on Linux and macOS/Darwin. Bootstrap still owns first
install and service unit creation;
`orbit update` updates the local Orbit CLI installation only.

Use [`update:all`](../2_update-all/update-all.md) when the operator needs to
roll out the same Orbit update across the fleet.

## Output

Run `orbit update` to see live progress and a final success, skip, or failure
result. Human output begins by checking for updates and only reveals download,
replace, and doctor steps when a newer version can actually be applied. See the
[terminal output contract](technical/6.1_update_output-render_human.md) for the
exact layout.

A failed step remains visible with captured download or verification output.

Use `--json` for the machine-readable local update result and any failure
metadata.

## Requirements

- The Orbit install root is writable (`ORBIT_INSTALL_PATH` or `$HOME/orbit`).
- Production artifact installs require a reachable release source (GitHub
  Releases by default, or the `ORBIT_BINARY_URL` override for offline and E2E
  artifact scenarios) plus permission to write the binary and update the
  owner-user local host launcher link.
- Source-mounted Docker/Incus development and E2E lanes require access to the
  mounted checkout and keep `/usr/local/bin/orbit` pointed at
  `<source>/apps/cli/orbit`.

## Related Commands

Use these commands before or after running `orbit update`.

- [`update:all`](../2_update-all/update-all.md) - update the local installation and
  every active supported Orbit installation
- [`doctor`](../3_doctor/doctor.md) - verify drift after updates

## Technical Contract

See [`update` technical contract](technical/1_update.md).
