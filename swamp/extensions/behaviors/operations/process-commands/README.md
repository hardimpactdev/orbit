# Shared Process command steps

Use these components inside the owning parent's native workflow. There is no installer behavior, child Operation, Gateway client or hidden replacement workflow. The parent owns dependencies, guards, recovery and terminal projection.

The @nckrtl/process-command-assets.read method takes {control, installation?} using the canonical public Process descriptors. It returns shipped script paths plus control_json and installation_json. It performs no host action. The decode({stdout}) method shapes one public script result for native guards and subsequent step inputs. Both metadata steps need the same exact Node placement as the commands.

Pass public serialized descriptors in these environment variables:

- ORBIT_PROCESS_CONTROL: the control_json output.
- ORBIT_PROCESS_INSTALLATION: the installation_json output.
- ORBIT_OPERATION_ID: the authoritative parent Operation ID.

For Docker, control.slot selects canonical (default), candidate, rollback-running, rollback-stopped or failed. The control.expected_spec field pins the artifact a step may use. These selectors never admit arbitrary names.

Resolve the existing installation vault descriptor only on material-consuming steps. ORBIT_PROCESS_MATERIAL accepts either the existing installation object or the existing batch map {processId: {installation: material}}. Transfer can therefore use its captured source and destination batches directly. No material read checks current Gateway placement during execution.

Example placed stage step:

    - name: stage
      target: '${{ "orbit-node-" + string(inputs.intent.node_id) }}'
      labels: { node: '${{ string(inputs.intent.node_id) }}' }
      task:
        type: behavior
        behaviorType: command/shell
        behaviorName: '${{ "process-stage-" + string(inputs.operation_id) }}'
        methodName: execute
        inputs:
          run: |
            sudo -n --preserve-env=ORBIT_PROCESS_CONTROL,ORBIT_PROCESS_INSTALLATION,ORBIT_OPERATION_ID,ORBIT_PROCESS_MATERIAL /usr/bin/python3 "${{ steps.assets.outputs.stage_unit_script }}"
          env:
            ORBIT_PROCESS_CONTROL: '${{ steps.assets.outputs.control_json }}'
            ORBIT_PROCESS_INSTALLATION: '${{ steps.assets.outputs.installation_json }}'
            ORBIT_OPERATION_ID: '${{ string(inputs.operation_id) }}'
            ORBIT_PROCESS_MATERIAL: '${{ vault.get(inputs.execution_secrets.installation.vault, inputs.execution_secrets.installation.key) }}'

Preserve names through sudo; never expand material into argv. Material-consuming scripts remove their vault environment value before launching tools. Scripts return one bounded public JSON object on stdout. Failure exits nonzero and emits only a fixed error_code; stderr and tool output stay private. The same output is available from the built-in shell step without a callback.

## Systemd actions

| Asset output | Action and public result |
| --- | --- |
| check_release_script | Check the optional admitted directory once; ready. |
| inspect_script | Read exact owned state; present and state. |
| environment_script | Publish immutable root-owned 0600 EnvironmentFile from vault material. |
| stage_unit_script | Stage the admitted unit privately and resolve admitted account home through local getent; ready and content_hash. |
| validate_unit_script | Run systemd-analyze on the staged unit. |
| backup_unit_script | Capture the previous unit privately, including an immutable absent marker; ready and previous_hash. |
| publish_unit_script | Publish the staged unit after the original-spec check. |
| reload_units_script | Run one systemd daemon reload, also after removal. |
| enable_script | One enable or disable, selected by public ORBIT_PROCESS_ENABLE. |
| control_script | One start, stop or restart, selected by public ORBIT_PROCESS_OPERATION. |
| verify_script | Assert ORBIT_PROCESS_DESIRED=running or stopped once; ready and state. |
| restore_unit_script | Restore the exact prior unit file, or remove a new unit; reload and activation remain separate. |
| remove_unit_script | Remove an already inactive owned unit. |
| remove_environment_script | Remove only immutable owned environment files after unit absence. |
| cleanup_checkpoint_script | Remove the verified staging/backup set after the parent no longer needs rollback. |

Decode stage stdout, then supply content_hash as ORBIT_PROCESS_STAGED_HASH to validation, publication and restoration. Decode backup stdout and pass its previous_hash as ORBIT_PROCESS_PREVIOUS_HASH; serialize a null value as the literal string null. No unit content or account home enters these outputs. Checkpoints are root-owned 0700 directories under /etc/orbit/process-commands/operation/process/admitted-hash.

Successful installation order is release check, inspect, environment, stage, validate, backup, publish, reload, boot policy, control and verify. Use native guards to skip settled work. After a failed activation, the parent composes stop, restore, reload, original boot policy, original state, and verify. Each tolerated failure must be checked by native conditions. Keep the checkpoint until recovery is no longer needed. Parent cleanup is explicit.

Hibernation stop uses control only. It must preserve boot policy and recorded desired-running state. Explicit stop composes disable and control. Retiring a unit composes stop, disable, remove, reload and environment removal.

## Docker actions

| Asset output | Action |
| --- | --- |
| pull_image_script | Ensure one admitted image is local, using vault material. |
| create_container_script | Create one canonical or candidate container using vault material over the fixed Unix socket; do not start it. |
| rename_container_script | Rename one pinned owned slot to ORBIT_PROCESS_DESTINATION_SLOT; replay proves the destination spec. |
| control_script | Start, stop or restart the selected owned slot. |
| inspect_script | Observe present, state and spec with optional expected-spec pinning. |
| verify_script | Check selected runtime state once. |
| check_health_script | Check public ORBIT_PROCESS_HEALTH_URL once; no retry/poll loop. |
| remove_container_script | Remove one inactive owned slot; optional ORBIT_PROCESS_ANONYMOUS_VOLUMES=true applies only to it. |

Create uses the fixed /var/run/docker.sock endpoint and sends private JSON in the HTTP request body. No command or environment value appears in argv. Labels remain orbit.managed=true, orbit.container.kind=process, Process ID and spec.

For replacement, inspect all canonical/candidate/rollback slots first. Native guards select a same-spec no-op, recovery or replacement. Pull and create the candidate while the original stays active. Rename the original to the matching rollback slot, stop that slot, rename candidate to canonical, start if desired, verify, check health if admitted, then remove the inactive rollback slot. Recovery moves the failed replacement aside, restores the rollback name and original state, verifies it, then retires the inactive failed slot. There is no hidden Docker state machine in a script. Native resume owns another attempt.

These components do not implement a Serve self-replacement protocol. Do not replace the Serve executing their parent run using a synchronous restart step. Durable mount dependencies also remain in the owning admitted host configuration.

## Verification

Python tests cover real files, ownership refusals, immutable environment paths, account resolution, replacement, restoration and replay. The Deno suite runs them and a disposable real Docker replacement/restoration test. Native tests exercise local command composition and actual remote-only script delivery with a scratch vault. They do not authorize fleet effects or prove a live family canary.
