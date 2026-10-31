---
title: "Swamp command steps"
description: "The shared execution-copy and terminal-output contract for native command steps."
covers:
  - apps/gateway/app/Infrastructure/Swamp/{StartSwampRun,SwampCommandStep,SwampVaultExecutionCopy,SwampRunSubscriber}.php
  - apps/gateway/app/Jobs/Operations/ProjectSwampTerminal.php
---

# Swamp command steps

Use `App\Infrastructure\Swamp\SwampCommandStep` for execution-copy references and terminal projection. Each family opts in with `workflow_inputs => true`; its projector returns the outer `{intent, execution_secrets}` envelope. The shared `DirectOperation::workflowInputs` builder validates names-only descriptors and writes authoritative Operation, kind and Node IDs last. An empty descriptor map serializes as an object. The separate `terminal_result` declaration fixes the result job and step; it does not opt into full inputs. Native `command/shell` owns execution and output capture. The Gateway records the result through the family's existing projector.

## Secret inputs

Call `executionCopy(string $vault, string $key, string $value): array{vault:string,key:string}` when the source value changes. The value is a sensitive parameter. The helper sends one native `vault.put` request over the authenticated TLS WebSocket and retains only a synchronization digest. The value is in `payload.value`, never process arguments. It accepts only a matching vault/key acknowledgement from a `local_encryption` vault before recording synchronization. Pass the returned descriptor in `execution_secrets`; never pass a secret value in workflow inputs.

Map each named variable with one whole native expression under `task.inputs.env`:

```yaml
inputs:
  run: '/usr/bin/python3 "${{ steps.assets.outputs.probe_script }}"'
  env:
    ORBIT_BOOTSTRAP_IDENTITY: '${{ vault.get(inputs.execution_secrets.bootstrap_identity.vault, inputs.execution_secrets.bootstrap_identity.key) }}'
```

Swamp resolves the value. The script uses stdin or a protected config file when handing it to a tool. It must not expand a secret into `run:`, argv or shell tracing. Environment delivery does not isolate the value from the worker user: `/proc` can expose the environment.

Official e459 accepts this server protocol even though its remote CLI refuses stdin. The request is `{type: "vault.put", id, payload: {vaultName, key, value, force: true}}`; no refresh hook is supplied. The primitive still requires approved vault authority; it adds no permission. Official e459 authorizes `write` on `vault:<name>` with the key in request context. That action also admits delete and annotate; a `methods: [put]` restriction does not narrow it because the handler supplies no method name. A put-only request does not authorize a broader write grant.

Upstream fixes go through the orchestrator to Nick. Only official Swamp versions may be pinned or distributed. The GitHub App key and Orbit CA private key stay only in the Gateway.

## Terminal outputs

A result step prints one public JSON object on stdout. Native `command/shell` stores it in `jobs[].steps[].outputs.stdout`, alongside `exitCode`, `executedAt`, `command`, `durationMs` and `stderr`. Keep secret values out of every output. A failed action can print `{"error_code":"family.code"}` and exit nonzero. Native dependencies skip subsequent actions.

The shared subscriber calls `projectTerminal(Operation $operation, array $terminalRun, array{job:string,step:string} $selector): Operation` with the native event's `run` object and the fixed family declaration. The helper makes one `workflow.history.get` request for that exact run, authenticated with the existing `swamp.read_token`. It does not require the separate `swamp.run_token` used to start work. It selects the named job and step and decodes stdout. After the history request finishes, it locks and reloads the Operation, checks its run binding and terminal state again, then calls the existing family `projection.apply`. No history request runs under that row lock. It does not repeat host or domain checks, poll status, resume a run or invoke an effect.

A `completed` event can carry a failed run status. Native `interrupted` maps to failed. A failed or cancelled run can have no selected output because the step was skipped. A successful run requires its public JSON object. Missing, mismatched or unreadable history leaves the Operation unchanged and raises `swamp.terminal_result_unavailable`; it never triggers resubmission.

### Event connection

The existing `orbit:agent-view` Gateway observer also keeps the shared native run connection. It joins the private `orbit` record channel; a committed native binding publishes an Operation notice there. After subscription acknowledgement and on observer startup, it reads the active bound Operations once to cover notices missed while disconnected. Only kinds with `terminal_result` are followed. It multiplexes `run.attach` requests on one native socket; receiving events never waits for a workflow to finish. Terminal delivery synchronously reads its history through the existing shared helper, independently of Horizon.

The native event connection and shared history reader accept messages and frames up to 8 MiB. Full run metadata and intent can exceed the Reverb client's 64 KiB message limit; Reverb keeps its existing limit. A run beyond the native bound cannot be projected through this connection and must not be treated as successful delivery.

A matching terminal frame is reduced to the Operation ID, bound run ID and actual native status. Laravel synchronously dispatches `ProjectSwampTerminal` in the existing Gateway observer process. It reloads the Operation and its declaration and calls the same `SwampCommandStep` helper. Terminal projection therefore remains available when Horizon is stopped, including a failed Horizon installation or restart. There is one history request per delivery and no native execution command.

An explicit attach `not_found`, or a `run.interrupted` reply naming the bound root, dispatches the same job without a claimed status for late-result recovery. Official e459 uses `run.interrupted` when the previous owner's heartbeat is stale; that reply alone does not prove persisted terminal history. The shared helper still requires the exact root's terminal history before projecting.

If projection fails, the observer keeps the notice and binding without closing the native event socket or reattaching the run. The existing receive loop retries that delivery after 5 seconds, doubling the interval to at most 60 seconds while delivery remains unavailable. An interrupted reply can arrive before terminal history. The Operation then stays active until a later delivery verifies terminal history. Other bound runs continue on the same socket. The observer drops the notice only after the handler returns. A process restart recovers the durable binding through the normal startup snapshot.

Authorization failures and `run.elsewhere` do not invent a terminal result. Duplicate frames are suppressed on the connection; the helper's row lock and terminal guard protect duplicate delivery after a process restart.

Native transport failures reconnect with exponential backoff from 1 to 60 seconds; a connection must remain healthy for 60 seconds before that backoff resets. Reconnection reattaches remembered active bindings without a retained terminal notice. It does not start, resume, cancel or retry a workflow. Families add no subscriber, callback, timer or status poller. Native resume of an already terminal Operation remains a separate lifecycle contract; this observer does not reopen it.

The start connection can receive `started` and a terminal event in the same message batch. After binding the confirmed root run, `StartSwampRun` announces the binding on the existing private record channel. The observer attaches, or uses its existing explicit late-attach recovery if the run has already finished. Admission never waits for a history request and never queues terminal projection to Horizon. An unavailable terminal result leaves the accepted run bound without changing the start outcome or resubmitting it.

Official e459 supports `run.attach` with `{runId, afterSeq}` for an active run. It returns `run.attached` and streams events on that request id. Completed runs leave the active registry, so this is not a durable subscription after completion. The shared event-connection owner must preserve the terminal event; families must not invent a polling fallback.

After an explicit late-attach miss, the shared subscriber can pass only the already-bound run ID to the same `projectTerminal` method. It reads history once and requires an actual terminal status and the original run/workflow/Operation/Node/kind identity. A supplied event status must still match history exactly. An active run, unavailable transport, authorization refusal or missing history causes no projection; no status is fabricated. This is one result-recovery read, not polling or resubmission.

A terminal event contains metadata, not step outputs. `workflow.history.get` responds with the full run under `payload.data` and includes outputs.

The synthetic dev-worker runs `2ebfa2a6-bb7e-4ad2-8a5b-867095984e93` and `2491206d-0531-442a-b5a5-60b988e989f6` proved success and failure through a second authenticated attach connection, then one history request per terminal event on official e459. This is a transport spike, not the live bootstrap canary.

## Node execution through official SSH

Node 209 hosts Serve and all Swamp workers. Converted Node effects use official `@swamp/ssh` from those workers; they install no target worker or Swamp binary and mint no worker token. Retained workers remain until their owning workflow placements migrate.

`SwampSshExecutionInput::identity()` returns the public control Node ID, existing 209 identity-file locator and approved public key. Bootstrap can read this before a target has a pinned identity; its parent owns first contact. `forNode(Node)` adds the public `target` fields (`name`, `host`, `user`, `port`, `host_keys`) from the stored target account, WireGuard address and complete host key. It refuses incomplete targets. The capture reads no private key, makes no connection and changes no account or access edge. Families compose the official native `task.globalArgs.name`, `transport` and `hosts` from this one capture.

The descriptor does not prove that a target currently authorizes the existing key. Lane 21 owns that coverage proof and worker retirement. Do not assume fleet-wide SSH `AcceptEnv` or sudo `env_keep`. Public Process control arguments can use official `exec.argv`; secret values must use a documented official delivery method and stay out of argv.
