---
title: "Swamp command steps"
description: "The shared execution-copy and terminal-output contract for native command steps."
covers:
  - apps/gateway/app/Infrastructure/Swamp/{SwampCommandStep,SwampVaultExecutionCopy}.php
---

# Swamp command steps

Use `App\Infrastructure\Swamp\SwampCommandStep` for execution-copy references and terminal projection. Each family opts in with `workflow_inputs => true`; its projector returns the outer `{intent, execution_secrets}` envelope. The shared `DirectOperation::workflowInputs` builder validates names-only descriptors and writes authoritative Operation, kind and Node IDs last. An empty descriptor map serializes as an object. The separate `terminal_result` declaration fixes the result job and step; it does not opt into full inputs. Native `command/shell` owns execution and output capture. The Gateway records the result through the family's existing projector.

## Secret inputs

Call `executionCopy(string $vault, string $key, string $value): array{vault:string,key:string}` when the source value changes. The value is a sensitive parameter. The helper delegates to the native vault push primitive, which sends the value on stdin and retains only a synchronization digest. Pass the returned descriptor in `execution_secrets`; never pass a secret value in workflow inputs.

Map each named variable with one whole native expression under `task.inputs.env`:

```yaml
inputs:
  run: '/usr/bin/python3 "${{ steps.assets.outputs.probe_script }}"'
  env:
    ORBIT_BOOTSTRAP_IDENTITY: '${{ vault.get(inputs.execution_secrets.bootstrap_identity.vault, inputs.execution_secrets.bootstrap_identity.key) }}'
```

Swamp resolves the value. The script uses stdin or a protected config file when handing it to a tool. It must not expand a secret into `run:`, argv or shell tracing. Environment delivery does not isolate the value from the worker user: `/proc` can expose the environment.

The primitive requires native remote stdin support and the exact approved vault permission. Official e459 does not accept remote vault-put stdin. That upstream gap remains blocked; the helper adds no access or runtime workaround. Upstream fixes go through the orchestrator to Nick. Only official Swamp versions may be pinned or distributed. The GitHub App key and Orbit CA private key stay only in the Gateway.

## Terminal outputs

A result step prints one public JSON object on stdout. Native `command/shell` stores it in `jobs[].steps[].outputs.stdout`, alongside `exitCode`, `executedAt`, `command`, `durationMs` and `stderr`. Keep secret values out of every output. A failed action can print `{"error_code":"family.code"}` and exit nonzero. Native dependencies skip subsequent actions.

The shared subscriber calls `projectTerminal(Operation $operation, array $terminalRun, array{job:string,step:string} $selector): Operation` with the native event's `run` object and the fixed family declaration. The helper makes one authenticated `workflow.history.get` request for that exact run. It selects the named job and step and decodes stdout. After the history request finishes, it locks and reloads the Operation, checks its run binding and terminal state again, then calls the existing family `projection.apply`. No history request runs under that row lock. It does not repeat host or domain checks, poll status, resume a run or invoke an effect.

A `completed` event can carry a failed run status. Native `interrupted` maps to failed. A failed or cancelled run can have no selected output because the step was skipped. A successful run requires its public JSON object. Missing, mismatched or unreadable history leaves the Operation unchanged and raises `swamp.terminal_result_unavailable`; it never triggers resubmission.

### Event connection

Official e459 supports `run.attach` with `{runId, afterSeq}` for an active run. It returns `run.attached` and streams events on that request id. Completed runs leave the active registry, so this is not a durable subscription after completion. The shared event-connection owner must preserve the terminal event; families must not invent a polling fallback.

After an explicit late-attach miss, the shared subscriber can pass only the already-bound run ID to the same `projectTerminal` method. It reads history once and requires an actual terminal status and the original run/workflow/Operation/Node/kind identity. A supplied event status must still match history exactly. An active run, unavailable transport, authorization refusal or missing history causes no projection; no status is fabricated. This is one result-recovery read, not polling or resubmission.

A terminal event contains metadata, not step outputs. `workflow.history.get` responds with the full run under `payload.data` and includes outputs.

The synthetic dev-worker runs `2ebfa2a6-bb7e-4ad2-8a5b-867095984e93` and `2491206d-0531-442a-b5a5-60b988e989f6` proved success and failure through a second authenticated attach connection, then one history request per terminal event on official e459. This is a transport spike, not the live bootstrap canary.
