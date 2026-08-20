# PHP Concepts

This document defines PHP-runtime-command-domain vocabulary and invariants. It
supports the PHP command contracts; it does not override the
[Architecture](../../architecture.md).

## Domain and runtime selection

These terms define the PHP command domain and how PHP runtime selections are tracked.

- **PHP runtime command domain:** The `php:*` command prefix. It owns PHP
  image version selection for Orbit-managed instance and workspace runtime
  containers, but it does not install host PHP runtimes or create a `php` state
  family.
- **PHP runtime selection:** Image version choice tracked by the gateway for
  one target scope: one instance's own version, or one workspace's own version.
  The app-level version is a creation-time template for new instances, not a
  live policy the existing ones follow.
- **PHP image selection:** PHP image version tracked by the gateway and used to
  create or recreate a FrankenPHP app runtime container for an instance or workspace.
- **Supported PHP version set:** Version set Orbit can manage through the PHP
  runtime catalog. Unsupported versions fail validation before PHP configuration or
  node artifacts are changed.
- **Available PHP image:** PHP image version available to the Docker runtime on
  one concrete Orbit instance or workspace serving node through the
  approved FrankenPHP image family. An instance write requires the image on that
  instance's serving node before the instance row is changed.
- **PHP runtime catalog:** Tool catalog knowledge that declares the PHP versions
  Orbit can manage and resolves each supported version to the approved
  FrankenPHP image reference that Orbit owns and builds from the upstream Debian/glibc image. Orbit's standard PHP app/workspace
  image family is `ghcr.io/hardimpactdev/orbit-frankenphp:{image-major}-php{version}-bookworm`; Alpine/musl,
  PHP-FPM, CLI-only, and host package references are invalid app/workspace
  runtime targets. The PHP 8.5 image family reports the same WAL-reset-safe
  SQLite release through both the SQLite3 extension and a SQL
  `sqlite_version()` query before publication. The catalog is evidence for
  selection and reporting, not a separate state family.
- **PHP runtime policy:** Shared runtime policy consumed by instance and workspace
  renderers. It carries the catalog-approved image reference, classic
  FrankenPHP mode default, OPcache defaults, realpath cache defaults, and
  optional preload behavior. Renderer families may add instance/workspace-specific
  container wiring, but they must not choose a host PHP or PHP-FPM fallback.
- **Gateway-tracked image facts:** Stored gateway facts about PHP images
  available on a node. `php:list` uses these by default instead of live node
  inspection. The inventory status distinguishes confirmed observations from
  stale facts and an unavailable Docker-compatible provider.
- **Live image inspection:** Explicit `php:list --live` behavior that asks the
  gateway to inspect the resolved node for available PHP images during the
  command and records the resulting approved image inventory. A
  supported node without an inventory fact receives one as part of that
  refresh. This does not bypass the Docker-compatible provider requirement. A
  successful empty inspection confirms that the approved image is missing;
  a failed inspection reports inventory unavailability without treating the
  image as confirmed missing.
- **PHP runtime view:** Shared PHP JSON entity reporting supported versions,
  the app-level creation template, and either one explicitly selected instance
  or workspace serving-node inventory. It never presents one arbitrary instance
  and node as the inventory for an app.

## Runtime Scopes

These terms define each target scope that a PHP command can read or write.

- **App PHP creation template:** App PHP version stored as gateway app
  configuration and copied onto each new instance at creation. It is read only
  when an instance is created; changing it never moves an instance or workspace
  that already exists, and no command writes it after `app:new`.
- **Instance PHP runtime version:** Concrete PHP version stored on the instance
  row and used by that instance's runtime container. A write selects one
  concrete instance, authorizes its serving node, verifies the approved image,
  stores the version on the instance, and reconciles that instance's
  Orbit-managed runtime artifacts. It never changes a sibling instance.
- **Workspace PHP runtime version:** Concrete PHP version stored on the
  workspace row. Orbit copies it from the owning instance at creation or writes
  an explicitly selected version for that workspace alone. A null value is
  invalid state and does not resolve through another row.
- **Effective workspace PHP version:** The workspace's own stored version.
- **Runtime PHP binary:** The `php` binary inside an app, workspace, or gateway
  runtime container — the web *serving* runtime and, in `orbit-gateway`, the
  gateway's own runtime. Instance setup, deploy commands, and ad-hoc
  PHP/Composer/Artisan invocations run on the app node's host PHP toolchain,
  matched to the resolved instance PHP version. Workspace setup steps receive
  the workspace effective version in `ORBIT_PHP_VERSION` while their host
  toolchain path still follows the app creation template.
- **Host PHP CLI variant:** The `php-cli` tool persists `coverage` or `standard`
  in `NodeTool.config.variant`. Coverage runtimes statically link PCOV with
  `pcov.enabled=1` for Pest TIA on `app-dev` nodes. Standard runtimes omit PCOV
  on `app-prod` nodes. Both variants install the same supported minors under
  `/opt/orbit/php/<minor>/bin/php`.

## Application and drift

These terms define what PHP commands apply to nodes and how partial application surfaces as drift.

- **PHP runtime container artifact:** FrankenPHP container configuration,
  endpoint, image tag, and service state on the node side, derived from instance or
  workspace PHP runtime configuration. The Process family owns artifact
  convergence; the Instance and Workspace families own the desired PHP
  configuration.
- **PHP runtime target:** Resolved app, concrete instance,
  workspace, or node-CLI scope that a PHP command reads or writes after target
  resolution and authorization.
- **Partial PHP application warning:** Structured Doctor handoff for a
  workspace result when its owning family permits a warning.

## Boundaries

These boundaries define what PHP runtime commands own and what they must not touch.

- **PHP-domain boundaries:** PHP runtime commands own concrete version selection,
  target resolution, runtime reporting, and partial-application warnings for
  `php:*`. They do not install or remove host PHP runtimes, own runtime image
  lifecycle, invent `doctor --family=php`, read `.php-version`, mutate Composer
  files, or change framework config. They also do not create app, instance, or workspace
  records, or treat PHP selection as proof that drift has converged.
