---
title: Route transfer publications
description: Capture immutable Route transfer inputs and apply public configuration with official file actions.
---

`NativeRouteTransferPublication::capture(array $transferPlan, array $destinationInstallations)` captures complete public `before` and `after` publications before physical cutover or terminal database projection. The parent calls it in its admission transaction after canonical Route and Process capture. The second argument is `process_installations.destination_installations`, including the admitted checkout and collision-adjusted Vite ports. No model is saved to render the prospective view.

Each Node row contains `node_id`, `user`, `control_node_id`, the existing Gateway SSH `connection`, and `before`/`after` Caddy, shared PHP and DNS artifacts. The connection comes from `NativeCaddyPublicationInput::connectionForNode`. Certificate scopes describe references; they do not authorize leaf deletion. Steps never fetch intent from the Gateway.

## Public configuration composition

An admission snapshot can become stale. The native workflows first read current public configuration on the effect Node. Typed metadata applies only the captured changed Caddy groups or PHP pools: current must equal the captured before value or the desired value. Other observed additions, changes and deletions remain intact. `direction: restore` reverses the captured delta. An owned conflict fails before a write.

### Caddy

`operation-route-caddy-delta` reads `/etc/caddy/Caddyfile`, composes the public candidate, and applies it through official `@swamp/cfgmgmt/file` on the Gateway worker. Separate Node-local steps run `caddy validate` and `systemctl reload caddy`, in that order. The metadata method is `@nckrtl/route-publication-command.caddy`.

### Shared PHP

`operation-route-php-delta` reads the admitted shared pool files. The metadata methods `phpPlan` and `php` select versions and compose pool text. Native `forEach` applies each changed file through official cfgmgmt, validates the affected FPM configurations with `php-fpm -t`, and reloads their services. Withdrawing the last pool removes the managed pool file. Existing local tuning remains under its owner. Runtime installation and service prerequisites belong to the parent.

### Parent composition

Both workflows receive the real parent `operation_id`, `kind`, `node_id`, empty `execution_secrets`, and `intent: {node_id, user, control_node_id, connection, before, after, direction}`. Their fixed terminal selector is `completion/result`. Logical Nodes 1 and 18 are outside these Node-local validation workflows.

There is no encoded payload channel, host staging file, custom transport, deployment journal, generation tracking, load receipt or custom lock. Configuration text travels through native inputs and outputs and official file actions. Validation failure skips reload. Re-running reads the current configuration again and converges. The observation and file application are separate steps; this lower does not claim an atomic comparison across them. Parents own the ordering of their publications and recovery.

## Scope and Swamp facilities

Checked official cfgmgmt 2026.10.27.1 file/template/line/systemd and official SSH copy. Official file handles writes and ownership. The systemd extension has no reload method, so each reload is one visible command. Format-aware composition is pure public-data policy: whole-file replacement and line matching do not preserve unrelated Caddy groups or PHP pool sections. Swamp owns placement, dependencies, fan-out and resume.

This lower supplies capture and Caddy/PHP publication steps. It does not switch the transfer parent or establish DNS application, loaded-certificate cleanup, archive custody or live transfer readiness. DNS and certificate-scope inventories must not be treated as proof of those effects.
