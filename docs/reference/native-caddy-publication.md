---
title: Native Caddy command steps
description: Publish admitted Caddy configuration through official SSH on Node 209.
---

The Gateway captures the complete public Node configuration before dispatch through `NativeCaddyPublicationInput::forNode(Node)`. It returns `node_id`, `user`, `caddy`, `control_node_id:209`, `host_keys` and `connection`. The shared `SwampSshExecutionInput` supplies the existing 209 identity-file locator and stored target pins. Route rendering selects the admitted prospective address when needed. No key bytes enter workflow inputs.

Each job and step in `workflow-operation-route-caddy.yaml` has one placement selector, `target: orbit-node-209`. Every step runs on a 209 worker. Official `@swamp/ssh` writes `/etc/caddy/Caddyfile`, validates it with `caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile`, then runs `systemctl reload caddy`. Public file content uses official exec stdin; file creation sets the existing root/caddy ownership and mode. Logical Nodes 1 and 18 use this same execution path.

A failed write or validation skips reload. A failed reload fails the run. Re-running applies the admitted configuration again. There is no deployment journal, generation tracking, custom transport or recovery branch. Official cfgmgmt 2026.10.27.1 file/template was checked: its public schema cannot accept the admitted host keys and its SSH default is accept-new. Official SSH supports the required pinned hosts and stdin directly. The official systemd extension has no reload method.

The workflow receives `intent.caddy_publication`, an empty `execution_secrets` object, and the actual parent `operation_id`, `kind` and `node_id`. The fixed terminal selector is `completion/result`, returning `ready`, `result` and `version`. The shared terminal subscriber projects that result; no step reads from or calls back to the Gateway. Transfer parents use the separate delta composition lower to preserve unrelated observed changes.
