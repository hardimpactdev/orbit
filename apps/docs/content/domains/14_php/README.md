# PHP Runtime Commands

PHP runtime commands select PHP image versions for Orbit-managed instance and
workspace runtime containers. They do not install host PHP runtimes; image
availability stays with Docker runtime preparation, and concrete runtime-unit
lifecycle stays with the Process family.

The PHP runtime command family owns the `php:*` command prefix.

## State Ownership

The `php` command domain does not own a state family. It writes runtime
selection configuration that is verified by existing state-family doctors.

[`doctor --family=tool`](../3_tool/tool-doctor.md) owns Docker runtime tool
availability. [`doctor --family=instance`](../5_app/instance-doctor.md) owns
instance PHP version selection and desired runtime configuration.
[`doctor --family=workspace`](../6_workspace/workspace-doctor.md) owns workspace
PHP version selection and desired runtime configuration.
[`doctor --family=process`](../7_process/process-doctor.md) owns the concrete
FrankenPHP runtime units, containers, lifecycle, logs, and repair.
[`doctor --family=proxy`](../8_proxy/proxy-doctor.md) owns instance and workspace
proxy backend artifact drift when PHP runtime targets change.
[`doctor --family=node`](../1_node/node-doctor.md) owns node runtime readiness.
There is no `doctor --family=php` contract.

## Domain Rules

These rules define what PHP runtime commands own and how they operate.

- PHP runtime commands are explicitly admitted tool-specific commands. This
  does not imply that every tool receives a top-level command family.
- `php:*` owns version selection, not runtime image build or host package
  installation.
- The PHP runtime catalog resolves supported PHP versions to Orbit-owned
  FrankenPHP image families built on upstream Debian/glibc FrankenPHP. PHP 8.5
  uses `ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-bookworm`; PHP 8.4 and
  8.3 use image major `1`. Host PHP, PHP-FPM, CLI-only PHP images, and
  Alpine/musl FrankenPHP variants are not supported fallback runtimes for app
  or workspace containers.
- The Instance and Workspace families own the selected PHP version and desired
  runtime configuration. The Process family owns applying that configuration to
  concrete runtime units and containers.
- A topology release candidate carries its digest-pinned PHP 8.5 image under an
  immutable candidate tag and as a checksummed topology artifact, so private
  registry credentials are not required on workload nodes. On candidate Linux
  app nodes, the update installer verifies and loads that artifact, then aliases
  the exact local image ID to the catalog's stable PHP 8.5 reference. This
  exercises the candidate without moving the stable registry tag before
  acceptance. If the FrankenPHP owned-input fingerprint is unchanged, the
  candidate tag is an alias of the previously accepted digest rather than a
  rebuild. After live acceptance,
  `orbit-release-candidate promote-runtime --build-id=<accepted-id> --accepted`
  moves that accepted build's recorded digest to the stable runtime-family tag
  and verifies the resulting digest; it does not create a GitHub release. That
  accepted identity is the only reuse source for later candidates. GitHub
  publication also aliases the same digest onto
  `ghcr.io/hardimpactdev/orbit-frankenphp:<VERSION>` before the release becomes
  public. The github-release manifest keeps the stable
  `ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-bookworm` family tag because
  PHP runtime selection matches that string exactly.
- Node CLI PHP selection is only supported for PHP 8.5. This matches the
  production native Orbit CLI binary artifact's embedded PHP version and does
  not limit app or workspace FrankenPHP runtime versions. Source-mounted
  Docker/Incus development and E2E nodes invoke `<source>/apps/cli/orbit`.
- App PHP version is gateway-tracked app configuration and acts as a
  creation-time template: new instances copy it, and changing it never reaches
  an instance or workspace that already exists.
- Instance PHP version is gateway-tracked instance configuration. Each instance
  owns the concrete version its runtime container uses, independent of its
  siblings and of the app template.
- Workspace PHP version is gateway-tracked workspace configuration, copied from
  the owning instance at creation unless an explicit version is supplied. A
  null value is invalid state and does not resolve through another row.
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
  resolve the target instance or workspace as input hint, but it never grants read
  or write authority by itself.

## PHP Runtime JSON Entity

PHP JSON renderers use this shape for runtime selection results:

```json
{
  "node": "app-1",
  "supported": ["8.5", "8.4", "8.3"],
  "available_images": ["8.5"],
  "image_inventory_status": "confirmed",
  "cli": "8.5",
  "app": {
    "name": "docs",
    "php_version": "8.5"
  },
  "instance": {
    "name": "development",
    "app": "docs",
    "php_version": "8.3"
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
- [`orbit app:*` and `orbit instance:*`](../5_app/README.md)
- [`orbit workspace:*`](../6_workspace/README.md)
- [`doctor --family=tool`](../3_tool/tool-doctor.md)
- [`doctor --family=instance`](../5_app/instance-doctor.md)
- [`doctor --family=workspace`](../6_workspace/workspace-doctor.md)
- [`doctor --family=proxy`](../8_proxy/proxy-doctor.md)
- [`doctor --family=node`](../1_node/node-doctor.md)
