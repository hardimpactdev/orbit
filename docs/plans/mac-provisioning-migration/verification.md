# Launchpad CLI Auto-Provisioning - Verification Criteria

Generated: 2026-01-12
Plan: docs/mac-provisioning-migration-plan.md

## Phase 1: CLI Command (in launchpad-cli)

| Check | Command | Expected | Source |
|-------|---------|----------|--------|
| SetupCommand.php exists | `ssh launchpad@10.8.0.16 'test -f ~/projects/launchpad-cli/app/Commands/SetupCommand.php && echo exists'` | `exists` | plan:64 |
| MacSetup.php exists | `ssh launchpad@10.8.0.16 'test -f ~/projects/launchpad-cli/app/Commands/Setup/MacSetup.php && echo exists'` | `exists` | plan:249-250 |
| LinuxSetup.php exists | `ssh launchpad@10.8.0.16 'test -f ~/projects/launchpad-cli/app/Commands/Setup/LinuxSetup.php && echo exists'` | `exists` | plan:251 |
| SetupProgress.php trait exists | `ssh launchpad@10.8.0.16 'test -f ~/projects/launchpad-cli/app/Commands/Setup/SetupProgress.php && echo exists'` | `exists` | plan:252 |
| Command has expected options | `ssh launchpad@10.8.0.16 'cd ~/projects/launchpad-cli && php launchpad setup --help' \| grep -c '\-\-tld\|\-\-php-versions\|\-\-skip-docker\|\-\-json'` | `4` | plan:67-72 |

## Phase 2: Desktop Integration

| Check | Command | Expected | Source |
|-------|---------|----------|--------|
| parseCliProgress method exists | `grep -c 'function parseCliProgress' app/Http/Controllers/ProvisioningController.php` | `1` | plan:325-355 |
| run() handles is_local | `grep -c 'is_local' app/Http/Controllers/ProvisioningController.php` | `>= 1` | plan:273-303 |
| Calls launchpad setup for local | `grep -c 'launchpad.*setup' app/Http/Controllers/ProvisioningController.php` | `>= 1` | plan:277 |
| localChecklistItems in Vue | `grep -c 'localChecklistItems' resources/js/pages/environments/Provisioning.vue` | `>= 1` | plan:363-369 |
| Provisioning tests pass | `php artisan test --filter=Provisioning` | exit 0 | test convention |

## Phase 3: Desktop Cleanup

| Check | Command | Expected | Source |
|-------|---------|----------|--------|
| MacPhpFpmService.php removed | `test ! -f app/Services/MacPhpFpmService.php && echo removed` | `removed` | plan:406 |
| MacBrewService.php removed | `test ! -f app/Services/MacBrewService.php && echo removed` | `removed` | plan:407 |
| MacHorizonService.php removed | `test ! -f app/Services/MacHorizonService.php && echo removed` | `removed` | plan:408 |
| CaddyfileGenerator.php removed | `test ! -f app/Services/CaddyfileGenerator.php && echo removed` | `removed` | plan:409 |
| MigrateToFpmCommand.php removed | `test ! -f app/Console/Commands/MigrateToFpmCommand.php && echo removed` | `removed` | plan:410 |
| DoctorCommand.php removed | `test ! -f app/Console/Commands/DoctorCommand.php && echo removed` | `removed` | plan:411 |
| StatusCommand.php removed | `test ! -f app/Console/Commands/StatusCommand.php && echo removed` | `removed` | plan:412 |
| mac-fpm-pool.stub removed | `test ! -f resources/stubs/mac-fpm-pool.stub && echo removed` | `removed` | plan:413 |
| horizon-launchd.plist.stub removed | `test ! -f resources/stubs/horizon-launchd.plist.stub && echo removed` | `removed` | plan:414 |
| DoctorServiceTest.php removed | `test ! -f tests/Unit/DoctorServiceTest.php && echo removed` | `removed` | user decision |
| DnsResolverService.php kept | `test -f app/Services/DnsResolverService.php && echo exists` | `exists` | plan:415 |
| All tests pass after cleanup | `php artisan test` | exit 0 | convention |

## Phase 4: Release

| Check | Command | Expected | Source |
|-------|---------|----------|--------|
| CLI phar builds | `ssh launchpad@10.8.0.16 'cd ~/projects/launchpad-cli && ~/.config/composer/vendor/bin/box compile'` | exit 0 | CLAUDE.md |
| GitHub release exists | `gh release view --repo nckrtl/launchpad-cli` | shows latest release | plan:417 |
| CLI version updated on server | `ssh launchpad@10.8.0.16 'launchpad --version'` | new version number | plan:418 |
| PHP-FPM services running (Mac) | `brew services list \| grep -E 'php.*started'` | matches | plan:447 |
| Caddy service running (Mac) | `brew services list \| grep 'caddy.*started'` | matches | plan:447 |
| Docker containers running | `docker ps \| grep -c launchpad` | `>= 4` | plan:448 |
| DNS resolution works | `dig launchpad.test @127.0.0.1 +short` | IP address | plan:449 |

## Summary

- Total phases: 4
- Total checks: 26
- Estimated verification time: ~60 seconds (excluding SSH latency)

### Notes

- Phase 1 checks run on remote server via SSH (`ssh launchpad@10.8.0.16`)
- Phase 2-3 checks run locally in launchpad-desktop repo
- Phase 4 checks are split between remote (CLI build/release) and local (Mac services)
- DoctorService and its test will be removed per user decision
