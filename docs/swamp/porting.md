---
title: "Port a kind to Swamp"
description: "Copy-and-fill workflow and Gateway checklist for every kind port."
---

# Port a kind to Swamp

Start every kind PR from this template. Review every kind PR against this page and the [binding shape guide](https://github.com/hardimpactdev/orbit/blob/main/swamp/docs/swamp-shape.md). Ship the Gateway admission, native workflow and old executor deletion together.

## Fill the workflow

Replace every `REPLACE_*` token, generate a new workflow UUID, and define the family's public intent schema. This example runs an action group on 209. For an effect on another Node, replace `command/shell` with official `@swamp/ssh` from 209, using the approved identity file and stored full host keys; see [Node execution](/reference/swamp-command-steps#node-execution-through-official-ssh). Set one placement selector per scope.

```yaml
id: REPLACE_WORKFLOW_UUID
name: operation-REPLACE_KIND
description: REPLACE_ACTION_GROUP
vaults: [orbit-control-inputs]
inputs:
  type: object
  properties:
    operation_id: {type: integer, minimum: 1}
    node_id: {type: integer, minimum: 1}
    kind: {type: string, enum: [REPLACE_KIND]}
    intent:
      type: object
      properties:
        start_tag: {type: string, minLength: 1}
        public_value: {type: string}
      required: [start_tag, public_value]
      additionalProperties: false
    execution_secrets:
      type: object
      properties:
        credential:
          type: object
          properties:
            vault: {type: string, enum: [orbit-control-inputs]}
            key: {type: string, pattern: '^REPLACE_KEY_PATTERN$'}
          required: [vault, key]
          additionalProperties: false
      required: [credential]
      additionalProperties: false
  required: [operation_id, node_id, kind, intent, execution_secrets]
  additionalProperties: false
jobs:
  - name: execute
    steps:
      - name: assets
        target: orbit-node-209
        task:
          type: behavior
          behaviorType: '@nckrtl/REPLACE_KIND-assets'
          behaviorName: '${{ "assets-" + run.id }}'
          methodName: read
      - name: action
        target: orbit-node-209
        dependsOn: [{step: assets, condition: {type: succeeded}}]
        task:
          type: behavior
          behaviorType: command/shell
          behaviorName: '${{ "action-" + run.id }}'
          methodName: execute
          inputs:
            run: REPLACE_F1_RUN
            env:
              ORBIT_OPERATION_ID: '${{ string(inputs.operation_id) }}'
              ORBIT_START_TAG: '${{ inputs.intent.start_tag }}'
              ORBIT_PUBLIC_VALUE: '${{ inputs.intent.public_value }}'
              ORBIT_CREDENTIAL: '${{ vault.get(inputs.execution_secrets.credential.vault, inputs.execution_secrets.credential.key) }}'
```

`assets.read` is a family metadata method: resolve shipped files with `ctx.extensionFile`, record public paths with `ctx.writeResource`, and return their paths. List scripts and imports under manifest-relative `additionalFiles`. Keep this method and the command on the same worker; never assume a checkout exists there. TypeScript shapes inputs and outputs; operational code lives in script files.

`REPLACE_F1_RUN` is the complete invocation of lane16's shared F1 error wrapper around the shipped action script. Fill it from the published wrapper's interface and the asset output paths; it is not a new command or wrapper API. F1 is assigned in PLAN and its concrete interface is pending publication. Use that shared implementation when available; do not add a family wrapper. Ports can proceed while that integration is prepared.

Each failed step exposes its step name and the last 4 KB of stderr, redacted, as public step output. The shared F1 projector records `failed_step` and `error.detail` for `operation:show`. Cover failure before action stdout exists, including asset and SSH failures. Never print credentials, private keys or unredacted diagnostics.

One script file owns one action group, such as all git commands for a checkout. Split steps only for a resume point, a different Node or a guard. Each group is idempotent for the admitted `operation_id` and start tag. Use native dependencies, guards, retries, concurrency and locks. Required apt/UFW host locks remain allowed when named. Add no poller, supervisor, receipt protocol or agent action.

The script prints one public result JSON object on stdout. A refusal supplies a public `error_code` and exits nonzero. Keep the native failure status. Scripts pass secrets to tools on stdin or in protected config files, never argv or tracing. Remove unused credential fields and vault declarations; declare every required vault in parents and children. Check actual principal, resource and key grants separately from names in YAML.

## Fill the Gateway declaration

Add these fields to the family's existing registration and return the full input envelope from its projector.

```php
// In the family's existing direct-operations registration:
'REPLACE_KIND' => [
    // Keep its existing admission, workflow and projector registration.
    'workflow_inputs' => true,
    'terminal_result' => ['job' => 'execute', 'step' => 'action'],
],
// Projector input(): ['intent' => $fullPublicIntent,
//                     'execution_secrets' => $namesOnlyDescriptors]
```

| Done | Gateway checklist |
| --- | --- |
| [ ] | Validate and record the complete immutable intent at admission. Use shared `StartSwampRun`. Let its builder set authoritative Operation, kind and Node identity. |
| [ ] | Steps read inputs. Remove Gateway intent, private-material and status callbacks. |
| [ ] | Use shared `SwampCommandStep::executionCopy` when a Gateway-owned secret changes. Pass only `{vault, key}` descriptors. Keep values out of persisted inputs and public outputs. |
| [ ] | Keep VM-created Pi/provider credentials on the VM. Consume them there through official SSH. |
| [ ] | Declare one fixed terminal selector. The shared subscriber and `projectTerminal` read the exact native run's history and invoke the family projector. |
| [ ] | Settle the Operation from native status and public outputs. Project F1 failure details for any failed step. |

## Check all seven boundary decisions

Apply every boundary decision to admission, execution and projection.

Atomic state and idempotency checks stay. Root CA and GitHub App private keys stay only in the Gateway. Steps may call only the exact POST mint/sign primitives scoped to the admitted positive numeric Operation. Operator-native resume reconciles through decision 5's exact identity lookup.

| Decision | Required behavior |
| --- | --- |
| 1. Serialization | Keep the generic Operation admission `lock_key`, released by terminal projection. Use `OperationStore::lockKey`. Add no family busy hold or custom mutex. |
| 2. Model state | Official models may keep sensitive state in the `default` vault with scoped grants. Add no separate outputs vault. |
| 3. Key custody | TLS leaf keys and agent rekey secrets stay on their service Node. Only public CSRs, certificates and verifier hashes move. |
| 4. Cancellation | Use the existing native admin/operator path. Shared run tokens get no global admin or custom cancellation channel. |
| 5. Uncertain start | Make one native-history lookup. Bind only on an exact admitted-identity match. No polling or automatic resubmission. |
| 6. Schedules | Publish full intent and updates into native schedules. Scheduled steps never POST admissions to the Gateway. |
| 7. Interrupted | Native `interrupted` is Gateway-terminal, projected failed. Never resume or reopen an existing Operation. Recovery uses a new admission or the family's normal retry. |

## PR and review checklist

Continue ports in the approved shape while live admission proof is pending. Use this checklist for every kind PR and its review.

- [ ] Name the core feature and official extension checked.
- [ ] Use defaults. Record each deviation's measured reason and removal condition in PLAN.
- [ ] Delete the old executor, embedded script strings and obsolete callbacks in the same PR.
- [ ] Report Orbit app/test net lines and Swamp lines separately.

Check the completed port and report what the evidence proves.

- [ ] Cover success and refusal. Check redacted failure detail, cancellation and interrupted projection.
- [ ] Validate completed YAML with the pinned native runtime. Run focused native tests and script lint.
- [ ] Run Gateway admission, identity and projection tests.
- [ ] Separate source conformance from checks, served-source uptake and live/grant evidence. Cite this page in the review.
