# PHP Runtime Implementation Patterns

Read this with `docs/abstractions/cross-cutting.md` before implementing PHP
runtime command ports.

Product behavior remains owned by `docs/commands/14_php/**` and the top-level
product docs.

## Domain Constraints

- PHP runtime is a command domain, not a state family.
- `php:*` owns version selection and reporting, not PHP runtime installation.
- `tool:*` and `doctor --family=tool` own PHP and PHP-FPM installation,
  updates, service lifecycle, and tool drift.
- App and workspace families own PHP-FPM artifact convergence after runtime
  selection changes.
- Proxy owns backend route artifact drift affected by app/workspace PHP target
  changes.
- Node owns node-level CLI PHP default drift.
- PHP commands must not read `.php-version`, mutate Composer files, or change
  framework config.

## Runtime Intent Pattern

- App PHP selection is stored as app gateway intent.
- Workspace PHP selection is stored as workspace override intent; missing
  workspace override means inheritance from the parent app.
- Node CLI PHP default is node-level gateway intent.
- Supported and installed PHP versions should come from the tool catalog and
  gateway-tracked node facts by default.
- Live installed-version inspection is explicit command behavior, not the
  default read path.

## Command Pattern

- `php:list` reports supported versions, installed versions, node CLI default,
  and effective app/workspace selections for the resolved target.
- `php:use` writes one runtime selection scope after resolving app, workspace,
  or node intent and authorization.
- Writes should attempt the owning-family artifact refresh that is currently
  available, then emit structured partial-enactment warnings pointing to the
  owning doctor family for remaining drift.
- Control and app callers use typed gateway API requests. Gateway callers use
  local gateway state and gateway-owned enactment services.

## E2E Pattern

- Use Docker feature E2E for target resolution, intent updates, and controlled
  artifact refresh behavior.
- Use Incus VM-feature only if a test needs real PHP-FPM service behavior or
  host-level CLI alternative switching.

## Evidence Pointers

- `docs/commands/14_php/README.md`
- `docs/commands/14_php/php-concepts.md`
- `docs/commands/14_php/1_php-list`
- `docs/commands/14_php/2_php-use`
- `docs/abstractions/cross-cutting.md`
- Old evidence: `../orbit-old-may/app/Settings/PhpSettings.php`
- Old evidence: `../orbit-old-may/app/Support/PhpVersion.php`
- Old evidence: `../orbit-old-may/app/Services/PhpFpmConfigManager.php`
- Old evidence: `../orbit-old-may/app/Services/ProductionPhpVersionResolver.php`
- Old evidence: `../orbit-old-may/app/Tools/Definitions/PhpToolDefinition.php`
- Old evidence: `../orbit-old-may/tests/Unit/Support/PhpVersionTest.php`
- Old evidence: `../orbit-old-may/tests/Feature/Services/PhpFpmConfigManagerOrbitPoolsTest.php`
