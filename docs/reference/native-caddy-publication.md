---
title: Native Caddy command steps
description: Publish admitted Caddy configuration with official configuration management.
---

The Gateway captures the complete public Node configuration before dispatch through `NativeCaddyPublicationInput::forNode(Node)`. The result contains `node_id`, `user`, `caddy`, `control_node_id` and `connection`. The connection names the existing Gateway SSH identity file and the Node's WireGuard address and account. No key bytes enter workflow inputs.

`workflow-operation-route-caddy.yaml` applies `/etc/caddy/Caddyfile` with official `@swamp/cfgmgmt/file` from the active Gateway worker. The following Node-local command validates the file with `caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile`. A successful validation permits one `systemctl reload caddy` command. The official systemd extension has no reload method.

Each action is a visible placed step. A failed write or validation skips reload. A failed reload fails the run. Re-running applies the admitted configuration again. There is no deployment journal, generation tracking or custom recovery branch. Official cfgmgmt manages the file and uses its normal SSH defaults.

The workflow receives `intent.caddy_publication`, an empty `execution_secrets` object, and the actual parent `operation_id`, `kind` and `node_id`. The fixed terminal selector is `completion/result`, returning `ready`, `result` and `version`. The shared terminal subscriber projects that result; no step reads from or calls back to the Gateway.

The capture describes one admitted snapshot. Transfer parents still need their owning publication-composition rule before using a snapshot across a placement change. This supplier does not establish that rule. Logical Nodes 1 and 18 remain outside this Node-local validation workflow.
