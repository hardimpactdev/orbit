# Placed Node commands

Follow the binding [Swamp shape guide](swamp-shape.md). Each operational action is one placed `command/shell` step. Script text lives in `run:` or in a shipped asset, never a TypeScript string. The Gateway supplies public intent and vault descriptors. A final step prints one public JSON object for the shared terminal projector.

Ordinary Node workers execute builtins on their own host. Logical Nodes 1 and 18 use the single shared SSH transport below. A raw builtin on either logical identity would execute on physical 209, so the Node-worker lint refuses it.

## Light-Node action

Locate the shared runner with `@nckrtl/node-command-assets.read`. The asset and action steps use the same target and string Node label. The action directly depends on successful asset resolution. Native dispatch ships the runner, supervisor and public proxy contract together through `additionalFiles`.

```yaml
- name: proxy-assets
  target: '${{ "orbit-node-" + string(inputs.node_id) }}'
  labels: {node: '${{ string(inputs.node_id) }}'}
  task:
    type: behavior
    behaviorType: '@nckrtl/node-command-assets'
    behaviorName: '${{ "node-command-assets-" + string(inputs.node_id) }}'
    methodName: read
- name: inspect
  target: '${{ "orbit-node-" + string(inputs.node_id) }}'
  labels: {node: '${{ string(inputs.node_id) }}'}
  dependsOn: [{step: proxy-assets, condition: {type: succeeded}}]
  task:
    type: behavior
    behaviorType: command/shell
    behaviorName: '${{ "inspect-" + string(inputs.node_id) }}'
    methodName: execute
    inputs:
      run: |
        /usr/bin/python3 -I "${{ steps["proxy-assets"].outputs.runner }}" --node-id "$ORBIT_EFFECT_NODE_ID" <<'ORBIT_ACTION'
        /usr/bin/hostname
        ORBIT_ACTION
      env:
        ORBIT_EFFECT_NODE_ID: '${{ string(inputs.node_id) }}'
```

Keep this local envelope exact. Put no command before or after it. The action body must contain **no Swamp expression**, including public input expressions. Swamp interpolates before the shell parses a heredoc; an input containing its delimiter could otherwise execute on 209. Pass dynamic values through declared environment variables instead.

All supplied environment names start with `ORBIT_`. Declare each forwarded public name with `--env ORBIT_NAME` and each private name with `--secret-env ORBIT_NAME`. Only `ORBIT_EFFECT_NODE_ID` may be supplied without a forwarding flag; its value equals the placement label. The lint rejects undeclared names and loader controls. Python starts with `-I`.

A private value is one whole `${{ vault.get(inputs.execution_secrets.password.vault, inputs.execution_secrets.password.key) }}` expression in `task.inputs.env`. Workflow inputs contain only `{vault, key}`. The runner sends the named value over SSH stdin. The remote action receives it in its environment and gives tools private bytes through stdin or a protected temporary config file. Never expand a private value into the action script, argv or shell tracing. Processes owned by the remote account can inspect that environment; this is the native vault environment contract.

| Option | Default | Limit |
| --- | --- | --- |
| `--node-id` | Required | 1 or 18 |
| `--env ORBIT_NAME` | No forwarded values | 64 names combined |
| `--secret-env ORBIT_NAME` | No forwarded values | Exact private output values are redacted |
| `--timeout-ms` | 60000 | 1–3600000 |
| `--max-output-bytes` | 65536 | 1–67108864 combined output bytes |

The action is at most 256 KiB; the request is at most 1 MiB. Output is bounded and buffered before emission. A failing action returns its exit code. Refusal errors contain fixed public codes, never the request or a traceback. A family needing a longer action also sets its native builtin `timeout` input. Recovery uses native workflow resume and dependencies, never a transport retry.

## Shipped Python action

A standalone family asset can replace the inline body. Its metadata `read` method returns a literal `script: ctx.extensionFile("scripts/hub_peer.py")` field. It runs at the same placement and the action directly depends on both metadata steps. Declare the asset under that family's manifest `additionalFiles`.

```yaml
run: |
  /usr/bin/python3 -I "${{ steps["proxy-assets"].outputs.runner }}" --node-id "$ORBIT_EFFECT_NODE_ID" --action-file "${{ steps["hub-assets"].outputs.script }}" --interpreter python3 --as-root --env ORBIT_HUB_CONTEXT
```

The runner accepts only a regular `.py` file beneath the same native worker cache's `bundles/<fingerprint>/files/scripts/` tree, including another package's bundle. It rejects symlinks, foreign ownership, writable-by-others paths and arbitrary local paths. It reads the bounded source on 209 and frames its **contents**, not its cache path, over SSH stdin. The remote command is fixed `/usr/bin/python3 -I -`; a light Node never needs access to 209's cache. Assets must be standalone standard-library scripts; sibling-module shipping is not part of this interface.

The default action identity is `orbit`. `--as-root` explicitly selects the already-approved remote root supervisor's root UID/GID with no supplementary groups. This is the same existing sudo authority available to inline actions, not a new SSH edge or grant. No public or private value becomes a remote argument. Pass action selection and intent in declared environment variables. The source lint requires a static literal `ctx.extensionFile` binding, matching placements and successful dependencies. Vault expressions must use `--secret-env`; public forwarding cannot silently omit private output redaction.

The shared metadata also exposes `identity_script`. The existing canary uses it to prove the asset form, while its separate disposable effects prove the inline form.

## Placement and custody

A fixed read-only sudo program verifies the protected Node209 identity and returns only a public success marker. It never prints config contents or changes permissions; existing root0600 config files are accepted.

The runner first verifies its actual inherited cgroup is the requested `orbit-node-proxy-worker-1.service` or `...18.service`. It verifies root custody of the existing 209 identity, selected proxy record, key metadata and exact pinned `known_hosts`. The single public mapping lives in `operations/node-command/scripts/proxy_contract.json`; the existing TypeScript installer and runner consume that same mapping. Inputs cannot choose an address, account, key or host key. There is no local action branch.

SSH reuses the approved key and access edge. It disables user config, agents, forwarding, TOFU and key updates. Source, request, action and values cross SSH stdin in bounded frames. No action or supervisor is installed remotely. The remote root supervisor verifies the expected WireGuard address and masked/PID0 local worker. It drops UID, GID and supplementary groups directly to the existing `orbit` account, preserving explicit variables without sudo environment filtering. Actions use existing passwordless sudo for admitted root effects.

The shared transport owns subprocess lifetime at this SSH boundary. It drains both output streams while sending input. Control-channel EOF on cancellation terminates the remote action group. Cleanup also kills descendants after their shell exits. Remote GNU timeout provides an independent deadline if a partition delays EOF. The transport creates no credential, grant, unit, binary or access edge.

## Verification

`tests/node_command_scripts_test.py` exercises real framed pipes and the transmitted supervisor: wrong placement, custody, identity, environment forwarding, private output redaction, overflow, timeout, EOF and background-child cleanup. `tests/node_command_native_test.py` uses official e459 with separate remote-only Serve and worker processes, shipped assets, a synthetic encrypted vault and a harmless SSH endpoint. It runs the production canary DAG with fixture-only host checks. This proves native dispatch and private value handling; live host identity remains a separate proof.

The existing `node-proxy-canary` workflow uses this runner for separate identity, directory, write, read, remove-file, remove-directory and public result actions. Live proof requires normally accepted source and the existing authorized canary route. Prove VPN1 before usexit18, record native run IDs, and keep both local workers masked.
