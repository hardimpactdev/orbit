# @swamp/ssh for all target Nodes

Node209 alone runs Serve and all Swamp workers. Run every target Node action, including VPN1 (Node1), usexit18 (Node18), Gateway14 and Beast9, through official `@swamp/ssh` on a Node209 worker. Node bootstrap installs no Swamp binary or worker and mints no worker token. Built-in `command/shell` executes on its worker, so placing it on209 does not execute it on either light box. Use the official extension with its defaults; add no family SSH adapter, wrapper or transport.

## Model and placement

Use registry package `@swamp/ssh@2026.10.23.1`, model version `2026.10.20.1`. The [official source](https://github.com/swamp-club/swamp-extensions/tree/d6f7afdcd6d04a63fdbc6ed56c403832770f67a6/ssh) and the [live canary workflow](../workflows/workflow-node-proxy-canary.yaml) own the API and working example. Normal installation uses `swamp extension pull @swamp/ssh@2026.10.23.1`.

Use one placement selector per scope: `target: orbit-node-209` or `labels: {node: '209'}`. A step can inherit its job's Node209 placement. Do not set both selectors on the same scope. Keep the affected Node in the admitted intent and select its host explicitly. Use the family's stable executing model name for `behaviorName`; the name below is an example, not a deployed administrative model. Stored models put the map under `globalArguments`. Workflow overrides put the **complete** `name`, `transport` and `hosts` map under `task.globalArgs`; `step.globalArgs` is invalid and existing model globals alone do not satisfy native validation of a dynamic model reference.

```yaml
id: 3b938de9-c4c5-48ca-99cd-8191572f4a0a
name: light-box-example
inputs:
  type: object
  properties:
    node_id: {type: integer, enum: [1, 18]}
  required: [node_id]
  additionalProperties: false
jobs:
- name: inspect
  target: orbit-node-209
  steps:
  - name: hostname
    target: orbit-node-209
    writes: true
    task:
      type: behavior
      behaviorType: '@swamp/ssh'
      behaviorName: light-box-example
      methodName: exec
      globalArgs: &ssh
        name: light-box-example
        transport:
          kind: ssh
          user: orbit
          identityFile: /run/credentials/orbit-node-worker.service/proxy-ssh-identity
        hosts:
        - name: vpn1
          address: 10.44.0.1
          transport:
            hostKeys:
            - ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG
        - name: usexit18
          address: 10.44.0.13
          transport:
            hostKeys:
            - ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIKTqizm/h9dgo7Zab5NUC1RlLprBkRoUPrKQkF/b+DjF
      inputs:
        hosts: '${{ inputs.node_id == 1 ? "name:vpn1" : "name:usexit18" }}'
        argv: [/usr/bin/hostname]
  - name: read-root-file
    target: orbit-node-209
    writes: true
    dependsOn: [{step: hostname, condition: {type: succeeded}}]
    task:
      type: behavior
      behaviorType: '@swamp/ssh'
      behaviorName: light-box-example
      methodName: script
      globalArgs: *ssh
      inputs:
        hosts: '${{ inputs.node_id == 1 ? "name:vpn1" : "name:usexit18" }}'
        sudo: true
        script: |
          cat /etc/hostname
```

Node209's `orbit-node-worker.service` already loads the approved existing key with `LoadCredential=proxy-ssh-identity:/etc/orbit/node-proxy/id_ed25519`. The `identityFile` above is the worker-readable systemd credential; do not point the orbit worker at the root-only source file. Nick approved this existing key for every Node through the tracked managed-account converge/footprint policy. Install no new key and do not hand-edit authorized_keys. Before migrating a Node, verify the official SSH route with that key and the stored full host pin. First-key publication may use the existing Gateway14 Node-SSH identity execution copy on209, solely for the tracked key-policy step. Remove that execution copy after every target verifies209's own identity. The live proof below covers1/18; it does not establish ordinary-Node access. `hostKeys` selects the official pinned policy. Keep the host pins shown above; do not substitute trust on first use.

## Methods, secrets and results

Use `exec` with `argv` for one command. Add `sudo: true` for root execution; the extension uses noninteractive sudo. For one script action, use `script`: its body travels on stdin to the default `sh -s --`. Set the official `interpreter` field only when the script requires another interpreter. Keep one action per step and use native dependencies, guards and cleanup steps as in the canary.

For a tool's stdin, `exec` accepts `inputs.stdin`; the canary's `read` step demonstrates it. A private JSON execution copy can be one whole `vault.get(...)` expression in this field, consumed by a shipped script with `json.load(sys.stdin)`. Invoke the script with public argv only. Official `runExec` records `hasStdin`, rather than the stdin value; keep secrets out of the action's stdout/stderr too. `script` uses stdin for the script body, so use `exec` when the action needs separate private stdin. Keep secret values out of `argv`, script text and tracing. Use only the official stdin or documented environment support. `inputs.env` uses OpenSSH SendEnv, so the remote server must accept each variable through AcceptEnv; root scripts also require sudoers env_keep. The example needs no environment changes. Do not add a custom secret channel.

The official step exposes `steps.<step>.outputs.stdout`. Native history records the per-host `runResult`, including stdout, stderr and exitCode; check the requested host and actual exit code. Use the shared Gateway terminal projection for family Operations. The direct canaries below created no Gateway Operation and do not prove a role, removal or Metrics parent.

Both live effect-and-cleanup runs succeeded with all six steps exiting0: VPN1 `6e7143ff-83c9-4c2a-8b33-6f9fad369ac3` and usexit18 `afbfc37b-4db5-457a-948d-0377bd510220`. The canary keeps UUID `0f7caec5-264b-42a9-afca-2f30e914c956` and inputs `{node_id: 1|18, effect: boolean}`. It uses a unique runtime directory and separate hostname, mkdir, write, compare, remove-file and remove-directory steps.

## Retirement prerequisite for roles, removal and Metrics

**Keep `orbit-node-proxy-worker-1.service` and `orbit-node-proxy-worker-18.service` running until accepted family callers stop selecting them.** An empty queue, an idle worker or a successful SSH canary does not clear this dependency.

| Owner | Required accepted replacement before unit retirement |
| --- | --- |
| Nodes0a: roles, converge and Provision | Replace dynamic logical1/18 worker selections and retire the corresponding parent callers. |
| Removal13 | Move participant DNS/network/residue and restore actions to official SSH; retain the required VPN1 DNS contributor. |
| Metrics12 | Move role Metrics and fleet/exporter fan-outs from logical1/18 worker identities to official SSH. |

Each family's port spec carries this prerequisite. Account for active, queued and saved/resumable roots that retain old placement before removing units. Complete native accounting on2026-10-31 found zero suspended runs; that does not migrate accepted dynamic callers or settle roots by itself. Owners supply exact IDs or checkpoint locators for any additional saved placement, or report none known. Preserve terminal history and schedule pauses; do not resume or settle roots merely to clear retirement.

After those prerequisites are complete, the runtime owner stops, disables and removes only the two obsolete proxy worker units and selections. Keep local light-Node workers masked. The sole issuer retires obsolete worker credentials. Preserve the existing SSH key, public pins and Node209 worker credential used above. Do not delete the whole proxy directory. This guide does not authorize DNS changes, new credentials or access edges.

## Move an ordinary Node

Use the sole public supplier `App\Infrastructure\Swamp\SwampSshExecutionInput::{identity,forNode}` when its source is accepted. `identity()` returns control Node209, the credential file locator above and the existing authorized public key. `forNode(Node)` adds `target: {name, host, user, port, host_keys}` from stored Node fields. It performs no SSH or credential read. Families translate that public descriptor into the complete official `task.globalArgs` map; do not add another provider. Prospective bootstrap or Retarget endpoints come from their admitted intent, with the family's full pin verification.

For each Node, publish the existing key through the tracked policy, prove pinned official SSH from209, move every accepted placement and worker-recreation path, account for active and resumable roots, then retire its worker, binary and worker-specific tokens. Follow the [single-Node retirement plan](single-swamp-node-retirement.md). An idle worker is not retirement clearance.
