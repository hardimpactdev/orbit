# route.caddy_converge verification cases

The `orbit` CLI has no command for this kind. The proof runs the Gateway console command `orbit:caddy-build NODE` against a fresh nested topology with a Gateway and a managed Linux Node that serves Routes through Caddy, and reads the task, the Node's files and real HTTPS responses. See [verifying-commands](../../../.agents/skills/verifying-commands/SKILL.md).

| Case | Setup and action | Required observations |
| --- | --- | --- |
| Publish | Change a stored Route so the rendered Caddyfile differs from the one on the Node, then build. | The command prints the Operation ID and exits 0. The task succeeds with `result: published` and a 32-character version. `/etc/caddy/Caddyfile` holds the rendered bytes, owned by `root:caddy` with mode 0640. Caddy serves the change over HTTPS. |
| Symlink | Make `/etc/caddy/Caddyfile` a symlink, then build. | The task succeeds and the path is a regular file with the rendered bytes. |
| Unchanged | Build twice with no change in between. | Both tasks succeed. The file is the same and Caddy keeps serving. |
| Validation fails | Store an intent that renders a file Caddy rejects. | The task fails at `Validate the Caddyfile`. Caddy is not reloaded and keeps serving its last configuration. The new bytes stay on the Node and the Gateway records no version. After the intent is corrected, the same command succeeds. |
| Reload fails | Make the reload fail. | The task fails at `Reload Caddy` and records no version. A rerun after the fix succeeds. |
| End check fails | Make the reload rewrite the file, for example with a reload hook. | The task fails at `Check the publication` and records no version. The new bytes stay. |
| Unreachable Node | Stop SSH on the Node, or change its host key. | The task fails at `Check the connection`. Nothing on the Node changes. |
| Unknown Node | Name a Node that does not exist. | The command prints `Node [NAME] does not exist.` and exits 1. No task. |
| Render refused | Store two Routes with the same address, then build. | The command prints the message of `caddy.render_failed` and exits 1. No task and no change. |
| No SSH target | Build a Node without an address, user or stored host key, including one whose host key is still pending, and one with the control identity missing. | The command prints the message of `swamp.ssh_target_unavailable` or `swamp.ssh_identity_unavailable` and exits 1. No task and no change. |
| No Caddy sites | Build a Node that serves no site or has no Caddy installed. | The command prints the problem and exits 1. No task and no change. Fails today: the render marks the Node `unserved` but the run ignores it. |
| Dry run | Run with `--dry-run`, then with `--dry-run --diff`. | The first prints the rendered file and changes nothing. The second prints one line for each site: `same`, `changed`, `build only` or `live only`, with the differing lines for a changed site, and ends with `Build version V, N sites.`. A render problem prints `Build refused:` and exits 1. |
| Diff options | Run with `--diff` alone, and with `--dry-run --diff` while the live file cannot be read. | The first prints `--diff only compares a render. Pass --dry-run --diff.` and exits 1. The second prints the read error and exits 1. No task and no change. |
| Two builds | Start a second build for the Node while one runs. | `operation.target_busy`. The first run is unaffected. Fails today: no hold is taken, so both run. |
| Cancel, interrupt and timeout | Cancel a run, let one outlast its budget, and stop the worker mid-run. | Each ends like a failed step. A rerun finishes. |
| Result | After a success and after a failure. | A success carries the version. A failure carries the error code and failed step and no version. The intent holds no secret. |
