# Task VM model keys as native command steps

`task-vm-model-issue` and `task-vm-model-revoke` provide the model-key step fragments for the existing Task VM parents. They take the complete public model intent and the three execution-copy descriptors. They do not create child Operations or contact the Gateway. Parent admission keeps the root Node identity and selects the admitted Gateway for every router step.

Each action is inline in a placed `command/shell` step. Anchor repair and key mutation use the stable `task-vm-model-router-<router-id>` behavior definition so native method locking serializes all participating writers. Revocation reads the fresh list, PUTs the list without every copy of the VM key, and verifies exact preservation of the anchor and unrelated keys. Issuance adds the reserved key with an idempotent PATCH. No secret enters a URL, command argument, output or diagnostic.

Vault values resolve only through whole-value `task.inputs.env` expressions. The Gateway obtains `{vault,key}` descriptors from lane 11's shared `SwampCommandStep::executionCopy`; this family has no vault transport. The workflow's final `model/result` step emits only `{task_vm_id,issued:true}` or `{task_vm_id,revoked:true}`. A parent consumes that public result, and the Gateway uses the shared terminal-result helper for its final projection. Revocation itself never clears database credentials or deletes a provider VM.

Access probes run once as separate steps. If the router has acknowledged a write but not yet reloaded access, the probe fails. Resume from that failed probe with native `swamp workflow resume`; successful mutation steps stay completed. There is no custom settling loop, retry YAML, Gateway binding wait or family lock.

These fragments are integration source for the right-shape parent rewrites. The existing parents are not switched by merely adding these workflows. Do not mark the PR ready until the full-intent admission and parent wiring consume them, shared vault/result helpers are available, the gate baseline shrinks, and the native operation proof is recorded. No new standalone model-key Operation kind is registered.
