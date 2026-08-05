# `orbit process:update [name]`

[Back to Process commands.](../README.md)

Update or rename a node-, instance-, or workspace-owned process definition.

`process:update` changes a process identity slug, command, restart policy,
crash notification policy, or process runtime for the resolved owner scope. It
re-renders every runtime unit derived from that process definition, replacing
derived unit names so they match the current identity slug.

## Usage

```bash
orbit process:update vite --instance=docs.production --command="npm run dev"
orbit process:update vite --instance=docs.production --label="Vite Dev Server"
orbit process:update queue --instance=docs.production --restart-policy=on_failure --restart
orbit process:update horizon --instance=docs.development --workspace=feature-docs --command="php artisan horizon"
orbit process:update orbit-hermes-dashboard --node=app-dev-1 --command="hermes dashboard --no-open" --runtime=systemd
orbit process:update watcher --instance=docs.production --runtime=systemd
orbit process:update worker --instance=feedback.development --runtime=launchd --restart
orbit process:update mysql --node=database-1 --name=app-mysql --json
orbit process:update vite --instance=docs.production --command="npm run dev" --json
```

## Behavior Summary

Use this command to update a process definition, optionally rename its identity
slug, change its display label, and re-render its runtime units.

- **Gateway Update**: Updates the gateway-owned process definition.
- **Display Label**: `--label` updates only the durable human display label.
  Identity rename via `--name` does not rewrite a persisted label (defaulted or
  custom).
- **Identity Rename**: `--name=<new-slug>` renames the process identity inside
  the owning scope after uniqueness validation.
- **Scope Resolution**: `--node` updates a node-owned process and cannot be
  combined with `--instance` or `--workspace`; `--workspace` updates a workspace-owned
  process for that workspace's instance; otherwise `--instance` updates an
  instance-owned process. Prefer `<app.instance>`; a bare project slug is
  accepted only when that project has exactly one instance.
- **Runtime Unit Replacement**: Re-renders the runtime units derived from the
  selected process definition and removes or replaces derived units that no
  longer match when the process identity changes.
- **Runtime Boundary**: Host-command processes use `systemd` on Linux nodes and
  `launchd` on macOS nodes. Managed services default to `docker` unless their
  catalog entry and Linux node platform admit `docker-swarm`.
- **Unsupported Rename Boundary**: Backends that cannot safely replace derived
  unit identity reject `--name` before changing gateway state.
- **Restart Behavior**: Does not restart running runtime units unless `--restart` is supplied.
- **Drift Reporting**: Reports repairable runtime-unit apply drift after successful configuration changes.

See also: [`process:add`](../1_process-add/process-add.md), [`process:restart`](../7_process-restart/process-restart.md), [`process-doctor.md`](../process-doctor.md).

***

**Technical Contract:** [`technical/1_process-update.md`](technical/1_process-update.md)
