# Shared CLI installation on a Node

Import `installCli` from `extensions/behaviors/operations/fleet/cli_install.ts`.
It accepts `{ user, release, profile }` and optional `{ command, signal }`.
`release` is null or the admitted `{ version, url, sha256 }` object. `command`
is the parent root's `NodeCommand` runner. Pass the same placed runner for
proxy Nodes. Omitting it uses the local Node worker. The lower starts no
Operation, HTTP request, agent command, child run or workflow.

The result is `{ installed: string | null, configured: boolean }` or
`{ error_code }`. A null `installed` means the Node kept its existing CLI.
The installer preserves a current or newer CLI with self-update, and source
builds with self-update. Missing or stale CLIs need an admitted release. Foreign
links or binaries refuse before execution. It holds the shared self-update
lock, rechecks the installed CLI after waiting, verifies HTTPS bytes against
the admitted SHA-256, checks the candidate as the managed user, and switches
`/usr/local/bin/orbit` with an atomic rename. A plain previous binary is kept as
`orbit.orbit-previous`. Root's profile is published at mode 0600. Candidate
failures leave the serving link unchanged. No diagnostic or profile contents
enter the result.

`convergeCli` in `cli_converge_local.ts` uses this lower after its admission
skip, plan-error and cancellation checks. A recipe parent calls `installCli`
directly as one step of its existing root. It owns cancellation before the
effect; once the bounded atomic installation starts, the lower returns its
actual outcome. Native execution failure remains uncertain and is thrown.
