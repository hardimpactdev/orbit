# Process lifecycle

The six Process commands use official cfgmgmt behaviors and SSH argv from Node 209. Every SSH mutation declares `writes: true`. Behaviors have stable names by Node and Process, so unrelated Processes do not wait on the same 60 s instance lock. Checks use cfgmgmt data and a native assert. The Gateway projects the successful assertion without a result-printing step. A failed run keeps its effects; rerun the command to converge. Start and restart project the accepted running state, even when admission captured a stopped desired state.

The public rendering method substitutes the observed account home in a systemd unit. It does no Node work. One argv command checks all admitted mount paths. No step creates a script directory or copies a script to a Node. Runtime ownership checks, checkpoints and rollback chains are removed by rule 22.

## Private files and Docker argv

Q-SECRET passed in a shared minimal@6 proof VM on Beast. The official file behavior received `vault.get` as `content`; create, changed content, drift, compliant checks and a refused write exposed no test content in data, the file changes array, histories, definitions or logs. See `swamp/proof/receipts/qsecret-file.json` and the reproducible `swamp/tests/cfgmgmt_file_vault_proof.py` probe.

Both runtimes write their environment with cfgmgmt file, root owner and mode 0600. Systemd keeps its immutable EnvironmentFile. Docker receives a raw env file path in one SSH `docker run -d` argv, along with exact command argv, labels, working directory, ports and mounts. Create performs one detached run; duplicate requests join the admitted operation and native resume skips its completed steps. Update removes the old container without removing volumes before one detached run. A stopped desired state uses a separate stop. The managed env file remains 0600 until destroy, including after a failed run. The official docker_container check confirms image, restart policy and state; its private current environment is stored only in the vault. No Process Node script remains.

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
| `check_release.py` | redundant pre-read removed; runtime start enforces its directory |
| `publish_environment.py`, `lifecycle_environment.py` | cfgmgmt file with vault content |
| `stage_unit.py`, `publish_unit.py` | cfgmgmt systemd unitFile |
| `validate_unit.py` | systemd loads the rendered unit; native end check |
| `backup_unit.py`, `restore_unit.py`, `rename_container.py`, `cleanup_checkpoint.py` | rollback machinery removed |
| `create_container.py`, `lifecycle_container.py` | SSH docker run argv and private env file |
| `check_health.py` | Analytics uses SSH curl argv |
| `lifecycle_listener.py` | SSH ss argv plus native readiness assert; ownership removed |
| `process_common.py`, `unit_files.py`, `docker_files.py` | obsolete script support removed |

The Analytics update caller no longer copies Process scripts. Its health request uses a bounded SSH curl command.

## Native verification

Run `deno test -A swamp/extensions/behaviors/operations/process-commands/process_native_test.ts`. The harness uses the pinned Swamp runtime, or `SWAMP_BIN`, and pulls the pinned official extensions into each disposable root. The suite covers all six kinds, reruns, failure, transitional systemd state, Docker argv and environment privacy, runtime changes, mounts and Vite readiness. The process-commands Deno shard runs it in CI.
