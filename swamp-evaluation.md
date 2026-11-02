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
