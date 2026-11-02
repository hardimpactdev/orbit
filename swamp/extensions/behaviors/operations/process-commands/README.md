# Process lifecycle

The six Process commands use official cfgmgmt behaviors and SSH argv from Node 209. Every SSH mutation declares `writes: true`. Behaviors have stable names by Node and Process, so unrelated Processes do not wait on the same 60 s instance lock. Checks use cfgmgmt data and a native assert. The Gateway projects the successful assertion without a result-printing step. A failed run keeps its effects; rerun the command to converge. Start and restart project the accepted running state, even when admission captured a stopped desired state.

The public rendering method substitutes the observed account home in a systemd unit. It does no Node work. One argv command checks all admitted mount paths. No step creates a script directory or copies a script to a Node. Runtime ownership checks, checkpoints and rollback chains are removed by rule 22.

## Named gap: Docker creation

The pinned `@swamp/cfgmgmt/docker_container` accepts one command string. It cannot preserve Process command argv, working directory or labels, and its environment option passes secrets as `docker create -e` arguments. The existing `lifecycle_container.py` therefore remains as the sole shipped script. Official SSH executes its body in memory, with public installation JSON in argv and environment-only vault content on stdin. The script sends the exact definition to the local Docker Engine socket. Its spec label skips settled creation on a rerun; it performs no ownership check. A changed spec removes the old container without deleting volumes before creating the replacement. Image pull, lifecycle commands, removal and end checks use official behaviors or one exec argv command.

EnvironmentFile delivery needs no script: SSH runs `install -D -m 0600 /dev/stdin <path>` with the vault value on stdin. This avoids the cfgmgmt file model's public `content` field. Public units and removals use cfgmgmt file/directory.

## Script audit

| Removed script | Replacement |
| --- | --- |
| `control.py` | cfgmgmt systemd apply/restart; SSH docker argv |
| `enable.py` | cfgmgmt systemd enabled |
| `verify.py` | cfgmgmt check plus native assert |
| `inspect.py` | cfgmgmt observes its target; ownership read removed |
| `reload_units.py` | SSH systemctl daemon-reload argv |
| `remove_unit.py` | cfgmgmt file absent |
| `remove_environment.py` | cfgmgmt directory absent |
| `pull_image.py` | cfgmgmt docker_image present |
| `remove_container.py` | cfgmgmt docker_container absent |
| `check_release.py` | SSH test -d argv |
| `publish_environment.py`, `lifecycle_environment.py` | SSH install with vault stdin |
| `stage_unit.py`, `publish_unit.py` | cfgmgmt file present |
| `validate_unit.py` | systemd loads the rendered unit; native end check |
| `backup_unit.py`, `restore_unit.py`, `rename_container.py`, `cleanup_checkpoint.py` | rollback machinery removed |
| `create_container.py` | the one named Docker creation gap above |
| `check_health.py` | Analytics uses SSH curl argv |
| `lifecycle_listener.py` | SSH ss argv plus native readiness assert; ownership removed |
| `process_common.py`, `unit_files.py`, `docker_files.py` | obsolete script support removed |

The Analytics update caller no longer copies Process scripts. Its health request uses a bounded SSH curl command.

## Native verification

With the repository's pinned Swamp runtime installed, run `SWAMP_BIN=/path/to/pinned/swamp python3 -m unittest discover -s swamp/extensions/behaviors/operations/process-commands -p process_native_test.py -v`. The suite pulls the pinned official extensions into each disposable root. It covers all six kinds, reruns, failure, transitional systemd state, Docker argv and environment privacy, runtime changes, mounts and Vite readiness. Native tests follow the Python test layout in the porting reference; the Deno shard has no native Swamp binary and runs the typed behavior tests.
