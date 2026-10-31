# The Swamp shape: how every Orbit operation must look

Read this before you port, write or review any Orbit operation in Swamp. It replaces the per-family contracts in orbit-swamp `docs/` that say "no shell", "private bytes on stdin" or "never a workflow argument". Where this guide and an older doc disagree, this guide wins. The source of truth is the Swamp manual: https://swamp-club.com/manual (Workflows, Behavior Definitions, Vaults, Data, Remote Execution).

Decided by Nick, 2026-10-31.

## The objective

Swamp does all operational work, the way Swamp is designed to be used, with Swamp defaults. The Gateway keeps state, the API/CLI/MCP, validation and intent records, and it projects results. orbit-agent only observes. If your code makes Swamp a thin wrapper around a hidden program, it is wrong, even if it is green.

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
   - The GitHub App key stays in the Gateway. Steps get short-lived minted tokens.
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

## Open points the pilot must settle

The pilot (firewall or node-bootstrap) settles these and updates this guide:
- How script files reach a remote worker. The manual says only the definitions a dispatch needs are bundled, so inline `run:` may be the only native form.
- The exact way to map a `vault.get` value to a named environment variable in `command/shell`.
- The manual's caveat that a vault value "appears in the command line" on the worker. Test it on a dev Node.
- How the Gateway reads a run's terminal outputs through the serve API.
