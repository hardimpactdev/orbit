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
   - The Gateway stays the source of truth. It pushes the execution copy with the native authenticated `vault.put` WebSocket request when a value changes. The value is in the frame payload, never argv; the shared Gateway helper launches no CLI.
   - Steps read `${{ vault.get('<vault>', '<key>') }}`. In `command/shell`, Swamp passes it as an environment variable. The approved Node209 worker SSH key instead uses official `@swamp/ssh` `identityFile` through native `LoadCredential`; worker-wide read is approved.
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

The gate follows static model references by name or UUID into their model definitions and type dependencies. Missing, ambiguous or dynamic references, and models without a supported static type, remain unknown.

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

The current template uses official `@swamp/ssh` package `2026.10.23.1` (model `2026.10.20.1`), checked at upstream commit `d6f7afdcd6d04a63fdbc6ed56c403832770f67a6`. The hand-built SSH launcher, key scanner and common Python module have been deleted. Official `exec` observes platform/architecture; `collect-host-public-key` observes the public key; one pure TypeScript step shapes their outputs. Stored public host keys use the official pinned policy. Missing pins fail native validation; the probe does not select trust on first use. The extension owns its default key agent and connection handling.

The original capability answers below were proved with synthetic data on a dev worker on Beast, using official Swamp `20261031.063736.0-sha.e459d219`. Serve and worker were separate processes joined through the native remote-only WebSocket dispatch. The SSH executable in the fixture was harmless; this proves native delivery and transport, not a live bootstrap or logical Node 1/18 proxy effect. No secret value was printed.

### 1. A remote command step can run a repository script file

This is the historical script-delivery capability proof, not permission to rebuild an SSH transport. A behavior manifest lists each script and its imports under `additionalFiles`. Native dispatch fingerprints and ships them to the worker cache. A public metadata method resolves `ctx.extensionFile("scripts/probe_host.py")` and records the path with `ctx.writeResource`. The next placed `command/shell` step executes that path. Keep metadata and execution on the same worker. Inline `run:` is not the only supported form.

Proof: native run `4ee4cd98-89c2-43de-8103-0d3746a19306`. The script path was inside the actual worker cache; the built-in shell ran it and retained public outputs. Asset helper modules arrived in the same bundle. Evidence: `evidence/lane11-vault-script-pilot-20261031/remote-script-history.json`.

The original proof did not cover logical Nodes 1/18. Their replacement uses official `@swamp/ssh` from a normal 209 control worker, as the canonical rule above states. The custom proxy runner and its earlier receipt are superseded; they are not a dependency or template.

### 2. A whole vault expression maps to a named environment variable

For `command/shell`, a whole `vault.get` expression belongs in `task.inputs.env`. For the approved Node209 worker SSH key, use `task.globalArgs.transport.identityFile` supplied through native `LoadCredential`. The vault-backed bootstrap pilot below uses `identityContent` for its Gateway-sourced copy:

```yaml
task:
  type: behavior
  behaviorType: '@swamp/ssh'
  behaviorName: bootstrap-ssh
  methodName: exec
  globalArgs:
    name: bootstrap-ssh
    transport:
      kind: ssh
      user: orbit
      identityContent: '${{ vault.get(inputs.execution_secrets.bootstrap_identity.vault, inputs.execution_secrets.bootstrap_identity.key) }}'
    hosts:
      - {name: target, address: '${{ inputs.intent.bootstrap.target.host }}'}
  inputs: {hosts: 'name:target', argv: [uname, -sm]}
```

`globalArgs` belongs inside `task` and supplies the complete globals map. The descriptor contains only `{vault, key}`. With its defaults the official extension loads identity content through stdin into a temporary SSH agent, handles cleanup and records public outputs. Orbit adds no key-file wrapper, environment channel or agent supervisor. Its documented default file fallback remains an upstream implementation detail.

Use the shared Gateway helper `App\Infrastructure\Swamp\SwampCommandStep::executionCopy(vault, key, value)` to obtain the descriptor. It sends the native `vault.put` frame over the authenticated serve WebSocket and retains only a synchronization digest. The request is `{type: "vault.put", id, payload: {vaultName, key, value, force: true}}`. Official e459 defines it in `src/serve/protocol.ts:170` and accepts it in `handleVaultPut` (`src/serve/handlers/vault_handlers.ts:331`). The value is in the frame, never argv; no CLI or upstream patch is needed.

The actual Gateway14 PHP helper wrote a synthetic value to an isolated official-e459 server on Beast: request `4542eeb7453ee8016d19fa9240f201ea`. Readback matched. During the request, 140 Gateway process command lines contained zero value matches, and scratch files contained no plaintext value. Evidence: `vault-frame-gateway-argv.json`; a separate Beast proof is `vault-frame-official-e459.json`, in the same evidence directory. The helper source is [Orbit2038](https://github.com/hardimpactdev/orbit/pull/2038).

Nick approved Gateway execution-input puts at 14:47Z and explicitly accepted the official e459 read exposure tracked in Lab #4226: the Gateway already holds the source secrets. Lane16 owns application through the normal backed-up grants path. The pilot grant names `user:orbit-gateway`, action `write`, resource `vault:orbit-control-inputs`, and condition `key == "bootstrap-identity-node-14"`; no wildcard or new token is needed. Official e459 also admits delete and annotate on that key. Those are separate write-action consequences, not the read bug; `methods: [put]` does not narrow them because the handler supplies no method name. The grant receipt must report the actual scope rather than claim put-only isolation. Workers receive only get/resolve for their exact step keys. No permission, production vault, binary or root-of-trust custody changed in the synthetic proofs. The authority decision is settled; grant application remains lane16-owned. Native `vault.get` checks the run-trigger principal with action `read`; the accepted raw-read exposure does not supply that runtime permission. The orchestrator also approved a separate Gateway `read` grant on this exact pilot key. Lane16 applies and verifies that grant; worker read permission alone does not prove Gateway-triggered resolution. Actual runtime resolution remains part of the live proof.

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

Attachment is active-run only. The native registry removes completed runs; it is not durable replay after completion. The shared event-connection owner must preserve the terminal event and handle this lifecycle. Do not invent `workflow.run.attach`, assume late replay, or add family polling. After an explicit late-attach miss, the shared subscriber may pass the already-bound ID without a status to the same `SwampCommandStep::projectTerminal`. It makes one history request and projects only if history has the original identity and an actual terminal status; active, missing, transport or authorization failure causes no write. If an event status is supplied, it must match exactly. This recovers a result without fabricating status or starting a second reader. The shared subscriber is merged in [Orbit2041](https://github.com/hardimpactdev/orbit/pull/2041), commit `1fc90c146d1302a2e4fdfb0ed3135af6c34ffd26`. The existing observer multiplexes native attachments and dispatches `ProjectSwampTerminal` synchronously; families add no listener. Real TLS tests cover all four terminal statuses, 1.1 MiB terminal metadata and 2.2 MiB history, within the shared 8 MiB native socket bound. Actual Gateway release and observer source uptake were verified at 14:32:13Z against that commit. The later live bootstrap run and its terminal Operation result are recorded in the Template section.

## Template

Use `node.bootstrap_probe` as the reference source. The live Gateway14 → Node209 run is `84a86453-d659-4b9c-a753-381d3ef7e315`, bound to Operation `6174`. All three native steps succeeded. The existing observer projected `probed: true`, `architecture: x86_64` and the expected public SSH host identity. The Operation succeeded at sequence 4 with no error after normal Gateway release 830 restarted the observer at 17:45:05Z. Its finished time preserves the native completion at 17:25:02Z. No native replay or manual projector call was needed.

- [Workflow](https://github.com/hardimpactdev/orbit/blob/main/swamp/workflows/workflow-operation-node-bootstrap-probe.yaml): full intent, vault references, native dependencies, official SSH methods and the fixed terminal result.
- [Official SSH](https://github.com/swamp-club/swamp-extensions/tree/d6f7afdcd6d04a63fdbc6ed56c403832770f67a6/ssh): package `2026.10.23.1`, normal registry pull and defaults. No vendored or patched transport.
- [Pure result decoder](https://github.com/hardimpactdev/orbit/blob/main/swamp/extensions/behaviors/operations/node-bootstrap/probe_result.ts): platform/architecture decisions and public JSON only; no process or Gateway client.
- [Shared helper](https://github.com/hardimpactdev/orbit/blob/main/apps/gateway/app/Infrastructure/Swamp/SwampCommandStep.php): native vault copy and one terminal history read.
- [Gateway declaration](https://github.com/hardimpactdev/orbit/blob/main/apps/gateway/app/Domain/Nodes/bootstrap-direct-operations.php) and [projector](https://github.com/hardimpactdev/orbit/blob/main/apps/gateway/app/Domain/Nodes/NodeBootstrapProjection.php): immutable intent and database projection.
- [Native regression](https://github.com/hardimpactdev/orbit/blob/main/swamp/tests/bootstrap_script_native_test.py): unmodified registry extension on a separate native worker, vault-backed key, native success/failure ordering and public terminal output. OpenSSH's documented binary test seam is harmless; this is not a live host canary.

Core native inputs, vault resolution, method output records, `run.attach` and `workflow.history.get` already fit. The official vault extensions provide external backends; this pilot uses core `local_encryption`. Official `@swamp/cfgmgmt/node.gather` was checked: its schema accepts only `sshIdentityFile`, not `identityContent`, and gathers additional OS/package details. Official SSH methods fit this vault-backed read-only probe without another transport. Every future mechanism PR records the same core/extension check.

Shared vault copies are merged in [Orbit2038](https://github.com/hardimpactdev/orbit/pull/2038); [Orbit2041](https://github.com/hardimpactdev/orbit/pull/2041) provides the shared subscriber and projection handler. The exact-key Gateway write/read and worker read grants were applied and installed-metadata policy checked at 15:02:36Z. The shared history reader uses the existing Gateway read credential, as fixed in [Orbit2071](https://github.com/hardimpactdev/orbit/pull/2071). The live run proves execution-copy resolution through the official SSH methods. Its terminal Operation projection is recorded below.

Source is [Orbit pilot 2003](https://github.com/hardimpactdev/orbit/pull/2003). The former custom SSH prototype is superseded. Preserve rule 5: GitHub App and Orbit CA private keys stay only in the Gateway. The only step-to-Gateway primitives are scoped token minting and CSR-plus-Operation certificate signing.

## Pilot comparison

The comparison uses imported baseline `a116589c527f55343626040f6effc8c7f5d8b746`, counting complete pilot-touched production files including public host-key capture. It excludes tests, documentation, shared generic contracts and the unmodified official registry package. Shared bootstrap lowers remain for other kinds. The earlier custom prototype is not the template.

| Measure | Before | Official template |
| --- | --- | --- |
| Touched production lines | 427 | 602 |
| Probe workflow lines | 44 | 117 |
| Custom Python SSH/scanner production lines | Not applicable | 0 |
| Native steps | Opaque `bootstrap/apply` | `inspect-platform`, `inspect-host-key`, `probe-host` |
| Step outputs | No public outputs on apply | Official SSH stdout/exit/timing and host key; public result JSON |
| Gateway calls from probe steps | Intent/private reads and result callback | None |
| Gateway projection | Family callback | Shared terminal event plus one history read |

For the same file scope, the superseded custom prototype had 659 production lines; the official version has 602. The pre-conversion history is `27d27930-753c-48c6-b00e-f715bc1220e3`. The historical script-delivery run `4ee4cd98-89c2-43de-8103-0d3746a19306` proves that capability only. Official-extension run `7bca63f5-a123-4733-8e3a-a9a4e5f02770` passed the pinned-key fixture; a missing-pin run fails native validation and skips the decoder. Current evidence is `official-ssh-native-history.json`; source counts are `source-comparison-official-ssh.json`, under `evidence/lane11-vault-script-pilot-20261031/`. Live native run `84a86453-d659-4b9c-a753-381d3ef7e315` started at 17:24:48.204Z and completed at 17:25:02.105Z. Its history shows `inspect-platform`, `inspect-host-key` and `probe-host` succeeded as separate steps. Operation `6174` contains their projected public JSON; the existing Node account remains `orbit`. Normal Gateway release 830 recovered the result through the existing observer. The final public API read confirmed success, sequence 4 and no error.

## Scheduled producers and results

Families publish captured non-secret schedule input through `SwampSchedulePublisher::publish(workflowId, schedule, inputs)`. The helper resolves the declared workflow name and sends the official `workflow.trigger.set` request, including nested `inputs`, then checks its acknowledgment. The configured credential needs `write` on that workflow; publication failure does not authorize a grant change or an alternate caller. The family owns capture, revision, cadence and publication after committed intent changes.

A scheduled root that has no original Operation uses `app/Domain/<Family>/scheduled-results.php`: its fixed workflow UUID maps to `workflow`, `terminal_result` and a `ScheduledResultProjection`. `context_workflow` is optional for producers that publish `trigger.inputs` directly. The existing shared commits-feed reader calls `activate()` and, at terminal, `applyScheduled(workflowId, run, result)`. The family keeps durable replay markers and uses its normal validated admission actions for downstream work. It never fabricates an original run binding. Steps do not POST admissions, and families add no observer or history poller. The row-based `scheduled_result` contract remains for observations of already admitted Operations.

Native schedule input publishers declare `publishes_inputs: true` on the exact standalone scheduled-result workflow. Its intended workflow grant contains run, read and the write action required by `workflow.trigger.set`. Other workflow and context grants stay run/read; no admin or blanket write authority is inferred.
