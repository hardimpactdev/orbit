# PHP Runtime Commands

PHP runtime commands select PHP versions for Orbit-managed app runtime,
workspace runtime, and node CLI defaults. They do not install PHP runtimes;
installation and service lifecycle stay in the `php` tool catalog entry and
the generic `tool:*` command surface.

The PHP runtime command family owns the `php:*` command prefix.

## State Ownership

The `php` command domain does not own a state family. It writes runtime
selection configuration that is verified by existing state-family doctors.

[`doctor --family=tool`](../3_tool/tool-doctor.md) owns PHP runtime
installation and PHP-FPM service drift. [`doctor --family=app`](../5_app/app-doctor.md)
owns app PHP runtime health and app PHP-FPM artifacts.
[`doctor --family=workspace`](../6_workspace/workspace-doctor.md) owns
workspace PHP runtime health and workspace PHP-FPM artifacts.
[`doctor --family=proxy`](../8_proxy/proxy-doctor.md) owns app and workspace
proxy backend artifact drift when PHP runtime targets change.
[`doctor --family=node`](../1_node/node-doctor.md) owns the default PHP CLI
configuration at the node level. There is no `doctor --family=php` contract.

## Domain Rules

These rules define what PHP runtime commands own and how they operate.

- PHP runtime commands are explicitly admitted tool-specific commands. This
  does not imply that every tool receives a top-level command family.
- `php:*` owns version selection, not runtime installation.
- `tool:install php`, `tool:remove php`, `tool:update php`, `tool:reload php`,
  `tool:logs php`, and `doctor --family=tool` own PHP-FPM tool lifecycle.
- App PHP version is gateway-tracked app configuration.
- Workspace PHP version is gateway-tracked workspace configuration. A workspace
  inherits the parent app PHP version unless it stores an override.
- Node CLI PHP version is gateway-tracked node configuration.
- PHP runtime commands must not read `.php-version` files.
- PHP runtime commands must not mutate `composer.json`, Composer constraints,
  lockfiles, framework config, or project source files.
- The CLI is a thin gateway client. The gateway authenticates the caller's
  WireGuard peer identity and applies authorization. Local cwd context can
  resolve the target app or workspace as input hint, but it never grants read
  or write authority by itself.

## PHP Runtime JSON Entity

PHP JSON renderers use this shape for runtime selection results:

```json
{
  "node": "app-1",
  "supported": ["8.4", "8.5"],
  "installed": ["8.5"],
  "cli": "8.5",
  "app": {
    "name": "docs",
    "php_version": "8.5"
  },
  "workspace": {
    "name": "feature-docs",
    "php_version": "8.4",
    "inherits": false
  }
}
```

`supported` is the version set Orbit can manage. `installed` is the
gateway-tracked version set by default and the live observed version set when a
command explicitly requests live inspection.

## Commands

The PHP family provides the following commands.

1. [`orbit php:list`](1_php-list/php-list.md)
2. [`orbit php:use [version]`](2_php-use/php-use.md)

## Related

Related command families and doctor contracts that intersect with PHP runtime selection.

- [`orbit tool:*`](../3_tool/README.md)
- [`orbit app:*`](../5_app/README.md)
- [`orbit workspace:*`](../6_workspace/README.md)
- [`doctor --family=tool`](../3_tool/tool-doctor.md)
- [`doctor --family=app`](../5_app/app-doctor.md)
- [`doctor --family=workspace`](../6_workspace/workspace-doctor.md)
- [`doctor --family=proxy`](../8_proxy/proxy-doctor.md)
- [`doctor --family=node`](../1_node/node-doctor.md)
