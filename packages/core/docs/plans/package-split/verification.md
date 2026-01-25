# Split orbit-core into orbit-core + orbit-ui - Verification Criteria

Generated: 2026-01-23
Plan: docs/plans/package-split/plan.md

## Phase 1: Create orbit-ui Repository & Structure

| Check | Command | Expected |
|-------|---------|----------|
| GitHub repo exists | `gh repo view hardimpactdev/orbit-ui --json name` | Returns repo details |
| Spatie skeleton structure | `test -f orbit-ui/composer.json && test -f orbit-ui/configure.php` | Exit code 0 |
| Symlink exists | `test -L ~/workspaces/orbit/orbit-ui` | Exit code 0 |
| Git initialized | `cd orbit-ui && git status` | Shows git status |
| Ready for UI migration | `test -d orbit-ui/src && test -d orbit-ui/resources/views` | Exit code 0 |

## Phase 2: Clean orbit-core Package & Update Namespace

| Check | Command | Expected |
|-------|---------|----------|
| No UI files remain | `! test -d orbit-core/src/Http && ! test -d orbit-core/routes` | Exit code 0 |
| Namespace updated | `grep -c "namespace HardImpact\\\\Orbit\\\\Core" orbit-core/src/` | > 0 |
| Service provider renamed | `test -f orbit-core/src/CoreServiceProvider.php` | Exit code 0 |
| No Inertia deps | `! grep -q "inertia" orbit-core/composer.json` | Exit code 0 |
| Factory namespace updated | `grep -c "namespace HardImpact\\\\Orbit\\\\Core\\\\Database\\\\Factories" orbit-core/database/factories/` | > 0 |
| PHPStan baseline valid | `cd orbit-core && vendor/bin/phpstan analyse --error-format=json` | No namespace errors |

## Phase 3: Configure orbit-ui Service Provider & Dependencies

| Check | Command | Expected |
|-------|---------|----------|
| UiServiceProvider configured | `grep -c "class UiServiceProvider" orbit-ui/src/` | = 1 |
| Routes register | `cd orbit-web && php artisan route:list \| grep -c "orbit"` | > 0 |
| Assets compile | `cd orbit-ui && bun run build` | Exit code 0 |
| MCP routes included | `test -f orbit-ui/routes/mcp.php` | Exit code 0 |
| Asset publishing tag | `grep -c "orbit-assets" orbit-ui/src/UiServiceProvider.php` | > 0 |
| orbit-core dependency | `grep -c "hardimpactdev/orbit-core" orbit-ui/composer.json` | = 1 |

## Phase 4: Test PHAR Build & Release orbit-core

| Check | Command | Expected |
|-------|---------|----------|
| PHAR builds | `cd orbit-cli && ./build-phar.sh` | Exit code 0 |
| No class errors | `php builds/orbit.phar --version 2>&1 \| grep -v "Class.*not found"` | Version output only |
| Commands work | `php builds/orbit.phar project:list --json` | Valid JSON output |
| No ambiguous classes | `cd orbit-cli && ./build-phar.sh 2>&1 \| grep -c "ambiguous class resolution"` | = 0 |
| orbit-core on packagist | `composer show hardimpactdev/orbit-core \| grep -c "0.1.0"` | = 1 |

## Phase 5: Update Consumers & Release orbit-ui

| Check | Command | Expected |
|-------|---------|----------|
| orbit-web updated | `cd orbit-web && grep -c "orbit-ui" composer.json` | = 1 |
| Routes updated | `grep -c "UiServiceProvider::routes" orbit-web/routes/web.php` | = 1 |
| Dashboard loads | `curl -s https://orbit-web.ccc \| grep -c "<div id=\"app\""` | = 1 |
| HMR works | `cd orbit-ui && test -f public/hot` | Exit code 0 (during dev) |
| No console errors | Browser console check | 0 errors |

## Phase 6: Final Testing & orbit-cli Release

| Check | Command | Expected |
|-------|---------|----------|
| Tests pass | `cd orbit-cli && ./vendor/bin/pest` | All tests pass |
| Final PHAR builds | `cd orbit-cli && ./build-phar.sh` | Exit code 0 |
| Upgrade works | `php builds/orbit.phar upgrade --check` | Shows new version |
| Full integration | Create project via CLI, view in web | Project appears in UI |
| All packages released | `gh release list --repo hardimpactdev/orbit-core --limit 1` | Shows v0.1.0 |

## Summary
- Total phases: 6
- Total checks: 30
- High-risk phases: Phase 2 (namespace migration), Phase 4 (PHAR verification)

## Key Decisions Made
- MCP routes go to orbit-ui
- Asset publishing happens from orbit-ui only  
- Factory namespaces follow pattern: `HardImpact\Orbit\Core\Database\Factories\`
- PHPStan baseline must be regenerated after namespace changes