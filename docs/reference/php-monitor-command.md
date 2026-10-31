---
title: PHP monitoring command steps
description: Compose dedicated production FPM monitoring without Gateway callbacks.
---

Metrics supplies the existing public `MetricsFleetCapture` pool descriptor (`enabled` or `disabled`) to the PHP monitoring metadata helper. The canonical Gateway renderer remains `ProductionPhpRuntimeConfigRenderer`; scripts do not recreate FPM configuration policy. No secrets or new private fields are needed.

The parent places metadata and every command on the runtime's admitted Node. Each action has one shipped script: stage the candidate, validate FPM configuration, publish the pool, reload the existing service, confirm the loaded generation, and inspect its public fingerprint. Native failure dependencies restore the previous pool, reload it when it was active, and finish restoration. No action starts a service that was stopped. Native resume owns retries.

The lower keeps the existing runtime identity, account-home ownership, local tuning and generation checks. It uses the canonical PHP host transaction lock for each action. A monitoring change may change only default-pool status directives; if current applications, unit, main configuration or master settings differ from captured input, it refuses rather than replacing an unrelated deployment. Each phase checks its own expected hashes before changing files or service state.

A missing dedicated runtime is observed as absent, with no adoption or creation. The parent uses native guards for the remaining actions. A pending foreign monitoring journal requires its owning recovery; the lower does not erase it. Journaled pool replacement preserves the old pool until confirmation or restoration completes.

The shared Caddy command supplier is [Caddy command supplier](https://github.com/hardimpactdev/orbit/pull/2020). Its immutable publication revision and survivor rule remains unresolved; PHP monitoring readiness does not resolve that Caddy blocker. Logical proxy Nodes 1 and 18 remain unavailable for built-in shell until the shared transport owner proves support. No live effects are authorized by this reference.


The metadata behavior is `@nckrtl/php-monitor-command.assets({runtime,run_id})`, where `runtime` is the unchanged public monitoring descriptor and `run_id` is the native parent run UUID. It returns `input_json` and script paths under `scripts`. Each command uses `task.inputs.env.ORBIT_PHP_MONITOR_INPUT` equal to the whole `input_json` expression and runs `sudo --preserve-env=ORBIT_PHP_MONITOR_INPUT /usr/bin/python3 "<script path>"`. The JSON envelope is limited to 64 KiB to stay below Linux's per-environment-value limit; larger intent refuses before execution. No secret is stored in this variable.

`swamp/workflows/workflow-operation-php-monitor.yaml` supplies the complete native dependency and recovery graph. It accepts the real parent `operation_id`, `node_id`, `kind`, `intent.php_runtime`, and an empty `execution_secrets` object. A parent may compose its visible commands using the shipped script paths; no new Gateway Operation kind is declared. Its terminal selector is `completion/result`. Public output distinguishes an absent runtime from a completed change, and includes the owned runtime fingerprint when present. `scripts.inspect` points to `inspect_pool.py` and returns only `present`, `enabled` and `fingerprint`.
