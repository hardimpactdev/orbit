# PHP Runtime Commands

PHP runtime commands select PHP image versions for Orbit-managed app and
workspace runtime containers. They do not install host PHP runtimes; image
availability and runtime lifecycle stay with Docker runtime preparation and the
owning app or workspace family.

The PHP runtime command family owns the `php:*` command prefix.

## State Ownership

The `php` command domain does not own a state family. It writes runtime
selection configuration that is verified by existing state-family doctors.

[`doctor --family=tool`](../3_tool/tool-doctor.md) owns Docker runtime tool
availability. [`doctor --family=instance`](../5_project/instance-doctor.md)
owns app PHP runtime health and app runtime containers.
[`doctor --family=workspace`](../6_workspace/workspace-doctor.md) owns
workspace PHP runtime health and workspace runtime containers.
[`doctor --family=proxy`](../8_proxy/proxy-doctor.md) owns app and workspace
proxy backend artifact drift when PHP runtime targets change.
[`doctor --family=node`](../1_node/node-doctor.md) owns node runtime readiness.
There is no `doctor --family=php` contract.

## Domain Rules

These rules define what PHP runtime commands own and how they operate.

- PHP runtime commands are explicitly admitted tool-specific commands. This
  does not imply that every tool receives a top-level command family.
- `php:*` owns version selection, not runtime image build or host package
  installation.
- The PHP runtime catalog resolves supported PHP versions to the Orbit-owned
  FrankenPHP image family built on upstream Debian/glibc FrankenPHP:
  `ghcr.io/hardimpactdev/orbit-frankenphp:1-php<version>-bookworm`. Host PHP, PHP-FPM, CLI-only PHP
  images, and Alpine/musl FrankenPHP variants are not supported fallback
  runtimes for app or workspace containers.
- The app and workspace families own applying the selected PHP image to their
  runtime containers.
- Node CLI PHP selection is only supported for PHP 8.5. This matches the
  production native Orbit CLI binary artifact's embedded PHP version and does
  not limit app or workspace FrankenPHP runtime versions. Source-mounted
  Docker/Incus development and E2E nodes invoke `<source>/apps/cli/orbit`.
- App PHP version is gateway-tracked app configuration.
- Workspace PHP version is gateway-tracked workspace configuration. A workspace
  inherits the parent project PHP version unless it stores an override.
- Workspace PHP reads and writes are available only when the workspace resolves
  to an active `app-dev` serving node and the caller is not an `app-prod` node.
  Explicit workspace targets fail with
  `workspace.unsupported_for_production` before inventory, configuration, or
  runtime effects when either side crosses that boundary. Instance-level PHP reads
  for production callers and targets omit workspace selection facts.
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
  "supported": ["8.5", "8.4", "8.3"],
  "available_images": ["8.5"],
  "project": {
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

`supported` is the version set Orbit can manage. `available_images` is the
gateway-tracked image set by default and the live observed image set when a
command explicitly requests live inspection.

## Commands

The PHP family provides the following commands.

1. [`orbit php:list`](1_php-list/php-list.md)
2. [`orbit php:use [version]`](2_php-use/php-use.md)

## Related

Related command families and doctor contracts that intersect with PHP runtime selection.

- [`orbit tool:*`](../3_tool/README.md)
- [`orbit app:*`](../5_project/README.md)
- [`orbit workspace:*`](../6_workspace/README.md)
- [`doctor --family=tool`](../3_tool/tool-doctor.md)
- [`doctor --family=instance`](../5_project/instance-doctor.md)
- [`doctor --family=workspace`](../6_workspace/workspace-doctor.md)
- [`doctor --family=proxy`](../8_proxy/proxy-doctor.md)
- [`doctor --family=node`](../1_node/node-doctor.md)
