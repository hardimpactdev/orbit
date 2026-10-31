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

## Open points settled by the spike

These answers were proved with synthetic data on a dev worker on Beast, using official Swamp `20261031.063736.0-sha.e459d219`. Serve and worker were separate processes joined through the native remote-only WebSocket dispatch. The SSH executable in the fixture was harmless; this proves native delivery and transport, not a live bootstrap or logical Node 1/18 proxy effect. No secret value was printed.

### 1. A remote command step can run a repository script file

A behavior manifest lists each script and its imports under `additionalFiles`. Native dispatch fingerprints and ships them to the worker cache. A public metadata method resolves `ctx.extensionFile("scripts/probe_host.py")` and records the path with `ctx.writeResource`. The next placed `command/shell` step executes that path. Keep metadata and execution on the same worker. Inline `run:` is not the only supported form.

Proof: native run `4ee4cd98-89c2-43de-8103-0d3746a19306`. The script path was inside the actual worker cache; the built-in shell ran it and retained public outputs. Asset helper modules arrived in the same bundle. Evidence: `evidence/lane11-vault-script-pilot-20261031/remote-script-history.json`.

Logical Nodes 1/18 are excluded from this proof. Official e459 runs built-in shell commands locally on their physical worker on 209 and has no supported SSH executor hook. Do not admit built-in shell jobs on those proxy identities until the supported shared transport is approved and proved. Existing TypeScript proxy tests do not cover it; no family SSH fallback is allowed. See `evidence/lane21-workers/official-e459-builtin-proxy-blocker.md`.

### 2. A whole vault expression maps to a named environment variable

Use `env` inside `task.inputs`, not workflow inputs or top-level `env`:

```yaml
inputs:
  run: '/usr/bin/python3 "${{ steps.assets.outputs.probe_script }}"'
  env:
    ORBIT_BOOTSTRAP_IDENTITY: '${{ vault.get(inputs.execution_secrets.bootstrap_identity.vault, inputs.execution_secrets.bootstrap_identity.key) }}'
```

The input descriptor contains only `{vault, key}`. The same remote run proved that the script received the synthetic value and the value stayed out of native history and scratch files. The script gives SSH a mode-0600 temporary identity file, removes the value from the child's environment, and removes the file after use.

Use the shared Gateway helper `App\Infrastructure\Swamp\SwampCommandStep::executionCopy(vault, key, value)` to obtain the descriptor. It sends the native `vault.put` frame over the authenticated serve WebSocket and retains only a synchronization digest. The request is `{type: "vault.put", id, payload: {vaultName, key, value, force: true}}`. Official e459 defines it in `src/serve/protocol.ts:170` and accepts it in `handleVaultPut` (`src/serve/handlers/vault_handlers.ts:331`). The value is in the frame, never argv; no CLI or upstream patch is needed.

The actual Gateway14 PHP helper wrote a synthetic value to an isolated official-e459 server on Beast: request `4542eeb7453ee8016d19fa9240f201ea`. Readback matched. During the request, 140 Gateway process command lines contained zero value matches, and scratch files contained no plaintext value. Evidence: `vault-frame-gateway-argv.json`; a separate Beast proof is `vault-frame-official-e459.json`, in the same evidence directory. The helper source is [Orbit2038](https://github.com/hardimpactdev/orbit/pull/2038).

Permission remains separate. At 14:00:38Z the installed `user:orbit-gateway` credential had no vault grants. Official e459 authorizes native `write` on `vault:orbit-control-inputs` with the exact key in resource context. That action also admits delete and annotate; `methods: [put]` does not narrow it because the handler supplies no method name. Only-put approval is not approval for that broader grant. Lane16 and Nick own the exact authority decision. No permission, production vault, binary or root-of-trust custody changed in these proofs.

### 3. The value is absent from shell argv, but child argv can expose it

Native dev-worker run `996e4603-e6b0-42c6-b8a2-e997731a9385` checked `/proc`. With whole-value environment delivery, neither shell nor script argv contained the synthetic vault value. An intentionally unsafe child invocation did expose it in the child's command line. The environment itself was readable through `/proc`; environment transport is not isolation from the worker user. Evidence: `evidence/lane11-vault-script-pilot-20261031/argv-probe.json`.

Never expand secrets into `run:`, command arguments or shell tracing. Scripts pass them to tools through stdin or protected config files.

### 4. A terminal event leads to one native history request

The start stream publishes `event.kind = started` with `event.runId`. Official e459 also supports an active-run attachment on a second authenticated socket:

```json
{"type":"run.attach","id":"follow","payload":{"runId":"<run-id>","afterSeq":0}}
```

The response is `run.attached`, followed by sequenced `event` frames on the same request id. A terminal frame has `event.kind = completed` or `cancelled` and `event.run`. A `completed` event can carry `event.run.status = failed`; it is not a success signal. The run metadata does not inline step outputs.

After the terminal event, send one request:

```json
{"type":"workflow.history.get","id":"result","payload":{"workflowIdOrName":"<run-id>"}}
```

The response has type `workflow.history.get` and the full run under `payload.data`. Select the named job and step in `jobs[].steps[]`; built-in shell output is in `outputs.stdout`, with `exitCode`, `executedAt`, `command`, `durationMs` and `stderr`. A result step prints one public JSON object. The shared `SwampCommandStep::projectTerminal(Operation, event.run, {job, step})` reads that history once, decodes the selected stdout and calls the existing family projector. Full-input opt-in is `workflow_inputs: true`. The family projector returns the outer `{intent, execution_secrets}` envelope; the shared builder writes authoritative IDs last. The separate fixed selector is `terminal_result: {job: bootstrap, step: probe-host}` for this pilot; it does not opt into full inputs. No family Gateway client, callback, poller or resubmission is needed.

Real official-e459 runs `2ebfa2a6-bb7e-4ad2-8a5b-867095984e93` and `2491206d-0531-442a-b5a5-60b988e989f6` proved success and failure after closing the start socket, attaching a second socket, and making exactly one history request per terminal event. A bad fingerprint failed the public scan and skipped the private probe. Raw sanitized frames and requests are in `terminal-attach-spike-succeeded.json` and `terminal-attach-spike-failed.json` beside the other evidence.

Attachment is active-run only. The native registry removes completed runs; it is not durable replay after completion. The shared event-connection owner must preserve the terminal event and handle this lifecycle. Do not invent `workflow.run.attach`, assume late replay, or add family polling. After an explicit late-attach miss, the shared subscriber may pass the already-bound ID without a status to the same `SwampCommandStep::projectTerminal`. It makes one history request and projects only if history has the original identity and an actual terminal status; active, missing, transport or authorization failure causes no write. If an event status is supplied, it must match exactly. This recovers a result without fabricating status or starting a second reader. Gateway subscriber integration and the live bootstrap canary remain separate from this completed transport spike.

## Template

Use `node.bootstrap_probe` as the reference source. The full live pilot is still pending; the spike does not assert live readiness or grant permission.

- [Workflow](https://github.com/hardimpactdev/orbit/blob/main/swamp/workflows/workflow-operation-node-bootstrap-probe.yaml): full public intent, vault/key references, exact placement and native dependencies.
- [Manifest and metadata](https://github.com/hardimpactdev/orbit/tree/main/swamp/extensions/behaviors/operations/node-bootstrap): `additionalFiles`, `ctx.extensionFile` and `ctx.writeResource`.
- [Scripts](https://github.com/hardimpactdev/orbit/tree/main/swamp/extensions/behaviors/operations/node-bootstrap/scripts): one host-key scan and one pinned SSH probe; no embedded TypeScript scripts or Gateway client.
- [Shared helper](https://github.com/hardimpactdev/orbit/blob/main/apps/gateway/app/Infrastructure/Swamp/SwampCommandStep.php): execution-copy references and one terminal history reader/stdout decoder.
- [Gateway declaration](https://github.com/hardimpactdev/orbit/blob/main/apps/gateway/app/Domain/Nodes/bootstrap-direct-operations.php) and [projector](https://github.com/hardimpactdev/orbit/blob/main/apps/gateway/app/Domain/Nodes/NodeBootstrapProjection.php): fixed result selector and database projection.
- [Native regression](https://github.com/hardimpactdev/orbit/blob/main/swamp/tests/bootstrap_script_native_test.py): real script delivery, vault environment, history outputs and failed-scan ordering.

Source during relocation: [Orbit pilot 2003](https://github.com/hardimpactdev/orbit/pull/2003), preserved from [Swamp478](https://github.com/hardimpactdev/orbit-swamp/pull/478), [Gateway shared helper2001](https://github.com/hardimpactdev/orbit/pull/2001), and [shared input and terminal-recovery contract2010](https://github.com/hardimpactdev/orbit/pull/2010). Add merged template and live Operation/run IDs after the full pilot passes. Preserve rule 5: the GitHub App key and Orbit CA private key stay only in the Gateway; the only step-to-Gateway primitives are scoped token minting and CSR-plus-Operation certificate signing.

## Pilot comparison

The comparison uses imported baseline `a116589c527f55343626040f6effc8c7f5d8b746`. Count complete production files touched by the probe conversion, excluding tests, documentation and shared contract 2010. Shared bootstrap lowers remain because the other bootstrap kinds still use them. This pilot adds source lines while exposing the actions separately.

| Measure | Before | After |
| --- | --- | --- |
| Touched production lines | 333 | 565 |
| Probe workflow lines | 44 | 76 |
| Gateway application change | Baseline | +23 net lines |
| Native step visibility | `bootstrap/apply`; `failure/settle-failure` skipped on success | `bootstrap/assets`, `scan-host-key`, `probe-host` |
| Recorded step outputs | No public outputs on `apply` | Metadata paths; each shell step's stdout, stderr, exit code and timing |
| Gateway calls from probe steps | Read intent/private material and post projection | None |
| Gateway result projection | Family callback | Shared terminal event plus one history read |

The dev-worker histories are `27d27930-753c-48c6-b00e-f715bc1220e3` before and `4ee4cd98-89c2-43de-8103-0d3746a19306` after. Evidence files are `before-history.json`, `remote-script-history.json` and `source-comparison.json` under `evidence/lane11-vault-script-pilot-20261031/`. The comparison describes source and synthetic dev-worker execution; the live Operation and run remain pending.
