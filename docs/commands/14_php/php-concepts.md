# PHP Concepts

This document defines PHP-runtime-command-domain vocabulary and invariants. It
supports the PHP command contracts; it does not override the
[Architecture](../../ARCHITECTURE.md).

## Domain And Runtime Selection

- **PHP runtime command domain:** The `php:*` command prefix. It owns PHP
  version selection for Orbit-managed app runtime, workspace runtime, and node
  CLI defaults, but it does not install PHP runtimes or create a `php` state
  family.
- **PHP runtime selection:** Gateway-tracked version choice for one target
  scope: app runtime, workspace runtime override or inheritance, or node CLI
  default.
- **Supported PHP version set:** Version set Orbit can manage through the PHP
  runtime catalog. Unsupported versions fail validation before PHP intent or
  node artifacts are changed.
- **Installed PHP runtime:** PHP version available on a target node. PHP
  commands may require the requested version to already be installed, but
  `tool:*` commands and the tool doctor own installation lifecycle and drift.
- **PHP runtime catalog:** Tool catalog knowledge that declares the PHP versions
  Orbit can manage and the PHP-FPM/runtime facts Orbit can report. It is
  catalog evidence for selection and reporting, not a separate state family.
- **Gateway-tracked installed-version facts:** Stored gateway facts about PHP
  versions installed on a node. `php:list` uses these by default instead of live
  node inspection.
- **Live installed-version inspection:** Explicit `php:list --live` behavior
  that asks the gateway to inspect the resolved node for installed PHP versions
  during the command.
- **PHP runtime view:** Shared PHP JSON entity reporting the resolved node,
  supported versions, installed versions, node CLI default, app PHP selection,
  and workspace effective selection when those scopes are resolved.

## Runtime Scopes

- **App PHP runtime selection:** App-scoped PHP version stored as gateway app
  intent. Changing it re-renders app PHP-FPM artifacts and affected app-owned
  proxy backend artifacts on the owning app node.
- **Workspace PHP runtime override:** Workspace-scoped PHP version stored on the
  workspace row. It overrides the parent app PHP version for that workspace.
- **Workspace PHP inheritance:** Workspace state where no workspace PHP override
  is stored and the workspace uses the parent app PHP version.
- **Effective workspace PHP version:** Version a workspace actually uses after
  applying workspace override or parent-app inheritance.
- **Node CLI PHP default:** Node-level gateway-tracked configuration for the
  default `php` binary used by users, agents, shell scripts, and lifecycle steps
  when no command selects a version explicitly. It is separate from app and
  workspace PHP-FPM runtime intent.

## Enactment And Drift

- **PHP-FPM artifact:** Node-side PHP-FPM pool configuration, endpoint, socket,
  or service state derived from app or workspace PHP runtime intent. App and
  workspace families own artifact convergence.
- **PHP runtime target:** Resolved app, workspace, or node CLI scope that a PHP
  command reads or writes after target resolution and authorization.
- **Partial PHP enactment warning:** Structured doctor handoff emitted when PHP
  intent is written but app, workspace, proxy, or node enactment does not fully
  converge. The warning keeps the owning state family and next doctor command
  visible.

## Boundaries

- **PHP-domain boundaries:** PHP runtime commands own selection, inheritance,
  target resolution, runtime reporting, and partial-enactment warnings for
  `php:*`. They do not install or remove PHP runtimes, own PHP-FPM tool
  lifecycle, invent `doctor --family=php`, read `.php-version`, mutate
  Composer files, change framework config, create app or workspace records, or
  treat PHP selection as proof that app, workspace, proxy, node, or tool drift
  has converged.
