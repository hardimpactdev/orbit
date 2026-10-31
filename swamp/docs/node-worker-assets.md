# Public worker bootstrap assets

`@nckrtl/node-worker-assets` version `2026.10.31.1`, method `read`, supplies public files for the enrollment parent's official SSH steps. Its inputs are exactly `{node_id, user}`. The parent owns admission, full host-key pin verification, the existing bootstrap-identity execution copy, placement, credential issuance/delivery and terminal projection. Nodes1/18 do not install local workers.

The result is also written as the native `assets` resource, so subsequent steps can use `steps.assets.outputs`. It contains `unit`, `checker`, `preflight`, `checker_path`, `unit_path`, `ca_path`, `runtime_version`, `artifact_url`, `artifact_sha256` and `artifact_bytes`. No input or output contains a credential. The scripts are shipped with the behavior manifest under `additionalFiles`; the metadata method reads them through `ctx.extensionFile`. TypeScript performs no host effect.

`extensions/behaviors/node-worker/config.ts` owns the unchanged official runtime pin and workerUnit renderer. The existing installer re-exports its previous public names from this shared policy. The native workflow validator reads the same canonical pin. The public scripts retain the existing account/platform/memory and worker-unit pre-start checks; they add no transport, supervisor, channel or custody policy.

## Compose with official SSH

Place the metadata read on the parent's admitted Gateway bootstrap worker, then use the official `@swamp/ssh` package `2026.10.23.1` (model `2026.10.20.1`) from that worker to the verified guest. Supply the complete `task.globalArgs` map, the existing `identityContent: vault.get(...)` bootstrap reference, and full pinned `hostKeys`, following the [native bootstrap probe](../workflows/workflow-operation-node-bootstrap-probe.yaml). Keep the family's stable executing model name. This creates no Gateway child Operation or callback.

- Run `preflight` through `script` with `interpreter: python3` and `sudo: true`. The asset already contains the admitted worker user and canonical memory threshold as public constants.
- Publish `checker` through `exec` with public argv `[/usr/bin/install, -m, '0755', /dev/stdin, /usr/local/libexec/orbit-node-worker-check]`, `stdin: '${{ steps.assets.outputs.checker }}'` and `sudo: true`. The parent creates `/usr/local/libexec` first. This uses the official stdin input; it needs no SendEnv/AcceptEnv policy.
- After the parent has delivered the two already-issued credentials, runtime binary, CA and working directory, publish `unit` through the same official file action with mode `0644` and destination `/etc/systemd/system/orbit-node-worker.service`. The unit's `ExecStartPre` requires the executable checker, so publish it before unit start. Keep daemon-reload, enable and start as separate native steps.
- Prove readiness with an identity step and a sudo step placed on the exact guest worker and Node label. Installing a unit is not worker readiness. The parent projects the result through the existing shared terminal helper.

Official `@swamp/cfgmgmt/file` provides file management when an approved identityFile is available. Its current schema has no identityContent input; this first-visit example instead uses official SSH exec/stdin with the existing bootstrap execution copy. No per-family SSH implementation or new credential is needed.

## Issuer boundary remains blocked

These assets do not provide a native issuer or a supported worker-token execution-copy reference. Official e459 administrative token commands exist, but dispatched methods cannot touch `_token-secrets` or keys beginning `worker-token-` / `server-token-`. Both capability.putSecret and protected secret loading apply that denial before grants. Do not compose placed builtin mint or reserved vault.get steps, move reserved keys to another vault, or bypass this through local datastore access.

Lane21 owns the exact official capability handoff; the orchestrator routes the missing mint-to-dispatchable-execution-copy decision to Nick. No upstream change, fork, non-official pin, grant or live issuance is part of this export. Full enrollment completion and its ETA remain conditional on that supported route. The scratch native fixture proves public asset/resource delivery into an unmodified official SSH file-action method; it performs no fleet installation.
