# The Swamp shape: how every Orbit operation must look

Read this before you port, write or review any Orbit operation in Swamp. It replaces the per-family contracts in orbit-swamp `docs/` that say "no shell", "private bytes on stdin" or "never a workflow argument". Where this guide and an older doc disagree, this guide wins. The source of truth is the Swamp manual: https://swamp-club.com/manual (Workflows, Behavior Definitions, Vaults, Data, Remote Execution).

Decided by Nick, 2026-10-31.

## The objective

Swamp does all operational work, the way Swamp is designed to be used, with Swamp defaults. The Gateway keeps state, the API/CLI/MCP, validation and intent records, and it projects results. orbit-agent only observes. If your code makes Swamp a thin wrapper around a hidden program, it is wrong, even if it is green.

## Use what Swamp provides first (Nick, 2026-10-31)

Before you build any mechanism, check Swamp core (https://swamp-club.com/manual) and the official extensions (https://github.com/swamp-club/swamp-extensions: ssh, vault, datastore, git, container-image, cfgmgmt, workflows, and others). If Swamp or an official extension does it, use that, with its defaults. Don't build custom wrappers, transports, supervisors, protocols or channels.
- Don't over-engineer for edge cases. All Orbit Nodes are trusted, so extra custody, placement or identity hardening beyond Swamp's defaults is not needed.
- Example: steps on vpn1 and usexit18 use the official `@swamp/ssh` extension (exec/script methods, pinned hostKeys, the approved 209 key, sudo where root is needed) from a normal 209 control worker. They don't use a custom SSH runner.
- A PR that adds a mechanism must say which core feature or official extension it checked, and why that doesn't fit.

## The shape

1. **The Gateway starts a run with the full intent as inputs.**
   - StartSwampRun passes every non-secret field the workflow needs as workflow inputs. Swamp records them in run history.
   - Secrets are never inputs. Pass the vault name and key name, and resolve them in the step.
   - Steps do not call back to the Gateway to read intent or report progress. There is no binding wait.
2. **The Gateway records the result from the run.** Steps return outputs. The Gateway projects the terminal result of the run. It does not re-validate it.
3. **One action is one step.**
   - Run operational commands as placed `command/shell` steps: `type: behavior`, `methodName: execute`, `run: ...`.
   - Use a typed TypeScript behavior only when you need a typed, schema-validated result.
   - A behavior that runs many actions in sequence is a workflow in disguise. Split it into steps.
4. **Scripts are visible and linted.**
   - The script text lives in the workflow or the behavior definition (`run: |`), or in a file that Swamp ships to the worker, never inside a TypeScript string.
   - CI runs shellcheck (and ruff for Python) on all of it.
5. **Secrets come from Swamp vaults.**
   - The Gateway stays the source of truth. It pushes the execution copy with `swamp vault put --server` (value on stdin) when a value changes.
   - Steps read `${{ vault.get('<vault>', '<key>') }}`. In `command/shell`, Swamp passes it as an environment variable.
   - Scripts pass a secret to a tool by stdin or a config file, never as an argument, because `ps` shows arguments to every user on the Node.
   - Root-of-trust keys stay only in the Gateway: the GitHub App key and the Orbit CA private key. Steps get short-lived minted GitHub tokens, and signed certificates by sending a CSR plus the operation id to the Gateway sign primitive. These two calls are the only Gateway calls a step may make. Never copy either key into a Swamp vault.
6. **Use native control flow, not your own.**
   - **Ordering:** `dependsOn` with `condition`.
   - **Fan-out:** `forEach`.
   - **Skip when already done:** `guard`.
   - **Tolerated failure:** `allowFailure`.
   - **Recovery:** `swamp workflow resume`; no hand-written retry loop.
   - **Parallelism:** `concurrency`.
   - **Waiting for a worker:** `queueTimeout`; inherit the 600s default.
   - **Waiting for a person:** `manual_approval`.
   - **Child work:** `type: workflow`.

   No `setTimeout` sleeps, no `while (true)` polling, no lock files when one of these fits. A host-level lock that a tool itself needs, such as apt or ufw, is allowed. Name it in the step.
7. **Placement on every step.**
   - Each step declares `target` or `labels`, and serve only relays (`--remote-only`).
   - A per-Node worker acts only on its own Node.
   - vpn1 and usexit18 run their steps through the SSH proxy workers on 209.
8. **No agent actions.** Do not call orbit-agent action commands from a step. Implement the action as steps. The agent is frozen and will be stripped to an observer.
9. **Swamp defaults.** Use the manual and CLI defaults for retention, GC, queueTimeout, concurrency and tokens. Any deviation needs a measured reason, recorded in PLAN.md.

## Checks on every PR

- `swamp workflow validate` for every workflow, on the pinned runtime.
- `deno test` for `extensions/behaviors/operations`.
- The placement lint.
- The Swamp-shape gate. It fails on:
  - scripts embedded in TypeScript;
  - many-action behaviors;
  - custom waits, retries or polling;
  - secrets not from `vault.get`;
  - step callbacks to the Gateway for intent;
  - agent action calls.

- Review checklist: does Swamp core or an official extension (swamp-club/swamp-extensions) already provide this mechanism? If yes, the PR uses it with its defaults; a custom wrapper, transport, supervisor, protocol or channel is a defect.

Existing violations are on a grandfather list that may only shrink.

## Before and after

Wrong (today's typical port):

```yaml
inputs: { properties: { operation_id: ..., node_id: ..., kind: ... } }
jobs:
  - name: run
    steps:
      - name: converge        # one step; reads intent from the Gateway, gets secrets on stdin,
        task:                 # runs a 400-line Python program embedded in a TypeScript string
          type: behavior
          behaviorType: "@nckrtl/node"
          methodName: converge
```

Right:

```yaml
inputs:
  properties:
    operation_id: { type: integer }
    node_id: { type: integer }
    packages: { type: array }
    password_key: { type: string }   # a vault key name, never the value
jobs:
  - name: packages
    steps:
      - name: install
        labels: { node: "${{ string(inputs.node_id) }}" }
        task:
          type: behavior
          behaviorType: command/shell
          methodName: execute
          inputs:
            run: |
              sudo apt-get install -y ${{ inputs.packages.join(' ') }}
  - name: database
    dependsOn: [{ job: packages, condition: { type: succeeded } }]
    steps:
      - name: set-password
        labels: { node: "${{ string(inputs.node_id) }}" }
        task:
          type: behavior
          behaviorType: command/shell
          methodName: execute
          inputs:
            run: |
              printf '%s' "$DB_PASSWORD" | sudo mysql-set-password
          # DB_PASSWORD comes from vault.get('orbit', inputs.password_key)
```

The second example is a sketch; the pilot fixes the exact syntax. Each action is a step that Swamp shows, can resume and can skip with `guard`.

## Settled pilot points

The `node.bootstrap_probe` pilot uses these native contracts. Its source is the template below. The live canary and Gateway terminal subscription are still pending; a passing isolated fixture is not live proof.

### Script delivery

A behavior manifest lists the Python files under `additionalFiles`. Swamp includes those bytes in its dispatch fingerprint and ships them to the remote worker cache. The metadata step calls `ctx.extensionFile("scripts/probe_host.py")` and records the cached path with `ctx.writeResource`. The next placed `command/shell` step runs that file. The helper module travels in the same bundle. Neither a shared checkout nor a script embedded in TypeScript is needed.

The actual remote-only worker fixture passed on native e459 and the compiled 614ab5ff build. It confirmed the returned script path was inside the worker cache and the built-in shell executed it. Keep each effect in its own step. Asset lookup is public metadata, not an effect runner.

### Vault environment syntax

Map the entire environment value to `vault.get` inside `task.inputs.env`:

```yaml
inputs:
  run: '/usr/bin/python3 "${{ steps.assets.outputs.probe_script }}"'
  env:
    ORBIT_BOOTSTRAP_IDENTITY: '${{ vault.get(inputs.execution_secrets.bootstrap_identity.vault, inputs.execution_secrets.bootstrap_identity.key) }}'
```

The workflow input contains only the closed descriptor `{vault, key}`. The Gateway pushes the execution copy on value changes with `swamp vault put --server`, value on stdin. Exact native put authority is separate from workflow resolution authority. Do not grant general vault write or raw secret read to make this work.

### The command-line caveat

The dev-worker `/proc` test used a synthetic value. Whole-value environment delivery did not put the value in the shell's or script's arguments. Passing that environment value as an argument to a child did expose it in `/proc/<pid>/cmdline`. The environment itself was readable through `/proc`; this transport is not isolation from the worker user.

The probe writes a mode-0600 temporary SSH identity, passes its path to SSH, removes the value from the child's environment, and removes the file after use. Never expand a secret in `run:`, argv or shell tracing. Native probe run `996e4603-e6b0-42c6-b8a2-e997731a9385` records this synthetic dev proof; it is not the live pilot canary.

### Terminal outputs

Native `completed` and `cancelled` events identify the run and its terminal status. A `completed` event can describe a failed run. The event's run metadata does not include step outputs, even with verbose enabled.

The Gateway subscribes to the native terminal event, then makes one `workflow.history.get` request for that run. Native history includes outputs. The pilot selects `jobs[bootstrap].steps[probe-host].outputs.stdout`, parses the public JSON, and gives it to the existing Node bootstrap projector. The declaration fixes the job and step names with `terminal_result: {job: bootstrap, step: probe-host}`. Steps make no Gateway HTTP calls. No family polling, callback, resubmission or receipt is needed.

The remote fixture recorded full non-secret inputs and the public probe result in history. A bad host fingerprint failed `scan-host-key` and skipped `probe-host`, including its private vault lookup.

## Template

Use `node.bootstrap_probe` for the next conversion. Preserve its separation between admitted inputs, native steps, outputs and Gateway projection.

- [Workflow](https://github.com/hardimpactdev/orbit/blob/main/swamp/workflows/workflow-operation-node-bootstrap-probe.yaml): full intent plus vault/key names; exact placement; native step dependencies.
- [Manifest and script assets](https://github.com/hardimpactdev/orbit/tree/main/swamp/extensions/behaviors/operations/node-bootstrap): native `additionalFiles` delivery and metadata-only `script_assets.ts`.
- [Scripts](https://github.com/hardimpactdev/orbit/tree/main/swamp/extensions/behaviors/operations/node-bootstrap/scripts): one host-key scan and one pinned SSH probe; no Gateway client.
- [Gateway admission](https://github.com/hardimpactdev/orbit/blob/main/apps/gateway/app/Actions/Nodes/AdmitNodeBootstrapAction.php) and [vault copy primitive](https://github.com/hardimpactdev/orbit/blob/main/apps/gateway/app/Infrastructure/Swamp/SwampVaultExecutionCopy.php): validate and store intent; synchronize the existing secret's execution copy; pass names only.
- [Gateway declaration](https://github.com/hardimpactdev/orbit/blob/main/apps/gateway/app/Domain/Nodes/bootstrap-direct-operations.php) and [projection](https://github.com/hardimpactdev/orbit/blob/main/apps/gateway/app/Domain/Nodes/NodeBootstrapProjection.php): select the fixed terminal output and record the result without repeating host checks.
- [Remote native regression](https://github.com/hardimpactdev/orbit/blob/main/swamp/tests/bootstrap_script_native_test.py): actual script delivery, vault environment, history output and failed-scan ordering.

Source PRs: [native workflow and scripts](https://github.com/hardimpactdev/orbit-swamp/pull/478) and [Gateway vault-copy primitive](https://github.com/hardimpactdev/orbit/pull/2001). These links identify the source during the monorepo move. Replace this pending status with the merged template, live Operation/run IDs and before/after evidence before declaring the migration resumed.
