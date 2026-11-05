# instance:deploy:automatic:resolve verification cases

The proof runs the built CLI against a fresh nested topology with a Gateway, a production Instance with automatic deployment, and a way to lose the automatic request. It checks the Gateway records, the operations and the deployments. See [verifying-commands](../../../.agents/skills/verifying-commands/SKILL.md).

| Case | Setup and action | Required observations |
| --- | --- | --- |
| Absent admission | Resolve a key whose admission never arrived. | The output is `Operation <id>: cancelled (operation.admission_fenced)` and the request ID. The command records a retained `cancelled` operation with zero attempts, no deployment and no hold on the Instance. JSON has `found` true. |
| Fence | Send the delayed automatic request after the fence. | It returns the cancelled receipt and deploys nothing. |
| Repeat | Resolve the same key again. | The same receipt is returned and nothing else is written. |
| Existing admission | Resolve a key whose deploy is running, then one that finished. | The receipt of the operation is returned unchanged, with the deployment when there is one. Nothing is cancelled, rewritten or started. |
| Fenced key | After a fence, let the automatic check run. | It uses a fresh key on its next run. |
| Worker lost | A terminal receipt from a lost worker without effect evidence. | The receipt shows it, and the hold stays until a manual deploy or rollback reconciles the Instance. Resolving starts no deploy. |
| Original caller | Resolve with `--caller-node` for the original Node, with another Node, and without it. | The first returns that caller's receipt or fences the key under it. Another Node, or none, fences the key under the wrong caller, and the real caller's delayed request then gets `operation.idempotency_conflict`; if an admission exists under another caller the command itself gives `operation.idempotency_conflict`. Activity records the acting and the original Node. |
| Conflicts | The same key with another Instance and with another commit. | `operation.idempotency_conflict`. No operation or deployment is written. |
| Input forms | A key of 129 characters or with a space, a short commit, an upper-case commit, and a `--caller-node` naming no Node. | `validation.failed` for each, with no operation or deployment written. |
| Missing input | Run the command without each argument, in a terminal and noninteractively, and with `--caller-node=abc` and an empty `--caller-node=`. | `instance.id_invalid`, `operation.request_key_required` and `deployment.commit_required`, with no request and no prompt; `node.id_invalid` for both `--caller-node` forms. Fails today: the three arguments are required, so the parser refuses a missing one with `input.invalid`. |
| IDs only | An Instance by name, the ID `0`, the ID `-1` passed after `--`, and `abc`. | `instance.id_invalid`, with no request. Fails today: `-1` without `--` is read as an option and gives a parser error. |
| Unknown and access | An Instance that does not exist, and a caller without Gateway access. | `http.404` and `node_access.required`. No operation or deployment is written. |
| Output forms | A wide and a narrow terminal, output piped to a file, and `--json`. | The operation line and the request ID at both widths. The piped output has no animation or escape codes. With `--json`, stdout holds only the receipt with `request_key`, `found`, `operation`, `deployment` and `request_id`. Fails today: the operation line names no Instance or Node. |
