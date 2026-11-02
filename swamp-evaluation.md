# Official cfgmgmt Docker container gaps

Pinned package: `@swamp/cfgmgmt@2026.10.27.1`.

Process creation uses one `@swamp/ssh exec` argv: `docker run -d --name NAME ... IMAGE ARGV`. The image is pulled with `docker_image`; the end check uses `docker_container`.

The container behavior lacks an env-file option, working directory, labels and a command argument list. Its command is one string. These gaps prevent faithful Process creation through its apply method. No shipped script is needed. The official check includes the observed environment in state, so Process stores that resource in the execution vault and projects only the native assertion.

## Process pass 3 observations

| Chart action | Pinned behavior operations kept visible | Candidate improvement |
| --- | --- | --- |
| Apply the unit | systemd apply; conditional daemon-reload; conditional restart | Could apply reload an unchanged unit after a failed activation, and activate a changed running unit? |
| Apply/stop/remove the unit | systemd apply; systemctl stop for activating units | Could ensure stopped also stop activating units? |
| Remove the unit | systemd stopped/disabled; file absent; daemon-reload | Could systemd offer ensure absent? |
| Replace the container | docker rm -f; docker run -d | Could docker_container support the Process options and exact argv? |
| Verify state | official check; native assert | Could a check optionally fail on drift while preserving its state data? |
| Publish Horizon and check it | systemd apply; check; native assert | Could systemd provide a bounded wait for the active state after a graceful application handoff? |

The Node observer does not currently record the Process user's home. Certificate paths therefore retain one getent observation and a pure public rendering step. A recorded account home would let admission finish that rendering and remove both steps. No new Node wrapper or script was added.

Official Docker state outputs use a generated vault key under `swamp-cfgmgmt-docker_container-<definition UUID>-<method>-state-<host>-current.env`. The Gateway grant permits that output namespace for apply/check only. Execution-input key grants remain unchanged.

## Tools candidates for Swamp evaluation

These are open questions from the Tools pass-3 reconciliation. Evidence uses Swamp e459 and cfgmgmt 2026.11.01.1. The workflow keeps separate operations today; it adds no wrapper executor.

| Extra workflow steps | Pinned behavior and candidate question |
| --- | --- |
| Prepare package commands; Choose package action; Decode package state | These existing behaviors only shape typed argv or observations. Could reusable workflow bindings and typed output projections remove these dispatches? |
| Fetch the signing key; Read the key fingerprint; Assert the key fingerprint; Dearmor the signing key | `fetch` requires a remote Node. Worker-local download, OpenPGP inspection and the native assertion are separate operations. Could an official worker-side download and verified-key resource fit? |
| Prepare apt keyrings; Publish the signing key; Pin repository packages; Write the apt source | Directory mode, a binary `copy_file`, and UTF-8 `file` resources have separate APIs. Could a resource set preserve these native checks under one progress name? |
| Refresh apt source | File apply does not refresh apt. The apt refresh method has no source scope or option to retain other indexes. Could those options fit the official behavior? |
| Remove the staged key | Public worker files need cleanup after completion or failure. Could native temporary-artifact lifetime remove this cleanup step? |
| Read manager version; Read installed package; Read macOS version | The manager version, inventory, candidate and cask/bottle compatibility are different command results. Could package observations expose these together, including normal candidate versions? |
| Check apt package / Check apt absence; Check the package / Check it is gone; Assert package state | Writes and checks are separate methods. Apt `check` records `non_compliant` and succeeds, so a native assert enforces the result. Would an opt-in failure on drift fit? Could checks expose the small public state in step outputs too? |
| Remove repository pins | `apt_repository.apply(ensure=absent)` removes its source and signing key, but does not manage apt preferences. Could optional preferences belong to that resource? The separate signing-key removal was redundant and is gone. |

Apt writes keep SSH argv because the official package resource cannot express `--no-remove` and the bounded dpkg lock wait. Homebrew keeps argv for its managed prefix, environment and bottle policy. Could those options fit the official resources? Installation on first use still uses the existing bootstrap boundary; it is not credited as fully rule 22 done.


## Node removal candidates

The removal family uses official cfgmgmt and direct SSH argv, with no shipped scripts. These operations remain separate native steps; no wrapper combines them.

| Chart action | Native operations and candidate question |
| --- | --- |
| Stop and remove services | systemd apply, guarded exec stop, file/directory apply, daemon-reload. Could systemd converge activating units to stopped and support absent units in one operation? |
| Remove role certificates | One rm argv handles the canonical current directory or symlink. Could an official absent resource handle both types? |
| Withdraw firewall access | Exact UFW argv and a native assertion preserve comments, routed rules and destinations. Could firewall represent those fields and retain the full rule identity? |
| Verify removal | Resource check methods and native assert. Could checks optionally fail on drift while retaining their state? |
| Purge role data | Guarded official exec covers Docker volumes and ordered Incus project/network/ACL deletion. Could native resource behaviors cover these absent operations? |
