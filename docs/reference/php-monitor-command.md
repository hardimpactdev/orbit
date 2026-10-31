---
title: PHP monitoring command steps
description: Apply dedicated production FPM monitoring with official file actions.
---

Metrics supplies the public `MetricsFleetCapture` runtime descriptor. `ProductionPhpRuntimeConfigRenderer` owns the pool text. The workflow also receives the existing Gateway SSH connection and `control_node_id` from `NativeCaddyPublicationInput::connectionForNode(Node)`. No private bytes are needed.

`workflow-operation-php-monitor.yaml` probes whether the dedicated runtime exists and whether the current release directory exists. The typed `@nckrtl/php-monitor-command.prepare` metadata method selects the captured current or initial pool. An absent runtime returns `present: false` without creating a runtime.

For a present runtime, official `@swamp/cfgmgmt/file` writes its generated `pool.conf` from the Gateway worker. A Node-local `php-fpm -t` command validates the runtime's existing main configuration. Successful validation permits one `systemctl reload` command for the dedicated service. Local tuning, the unit and other runtime files remain under their existing owners.

A failed write or validation skips reload. A reload failure fails the run. Re-running converges the pool. Monitoring has no journal, generation receipt, stopped-service preservation or custom compensation. The official systemd extension has no reload method, so reload is one visible command step.

The parent retains its real `operation_id`, `node_id` and `kind`. `intent.node_id` names the effect Node, and `intent.php_runtime` is the captured runtime. Each action has explicit placement. The fixed terminal selector is `completion/result`, returning `ready` and `present`. No new Gateway Operation kind, Gateway callback, execution secret or listener is introduced.
