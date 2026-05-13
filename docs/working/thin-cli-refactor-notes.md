# Thin CLI Refactor Notes

## 2026-05-13

- Read the canonical model docs: `MISSION.md`, `ARCHITECTURE.md`, `BUILDING-BLOCKS.md`, `CONCEPTS.md`, and node concepts.
- Started with the config/schema slice.
- Existing migrations already added a nullable `nodes.user` beside `nodes.ssh_user`; the cleanup migration therefore copies missing `user` values from `ssh_user`, then drops `ssh_user` and `is_local`.

- Updated `doctor` to use `config("orbit.is_gateway")` for gateway-local execution and to treat `--fix`, `--restore`, and `--adopt` as mutually-exclusive standalone modes.
- Updated the doctor contract test to stop seeding `nodes.is_local` and to call `--restore`/`--adopt` without `--fix`.
- Added `ORBIT_IS_GATEWAY` config/env support and verified `php artisan config:show orbit.is_gateway`.
- Added the cleanup migration for `nodes.user`, removed `ssh_user`/`is_local` from the `Node` model and factory, and ran `php artisan migrate --no-interaction`.
- Renamed `node:new --ssh-user` and the gateway request body field to `user`; focused `node:new` and doctor tests passed earlier in the pass.
- Removed CLI command `callerRole()` usage and direct `Node::where('is_local', true)`/`ssh_user` references from live app code. Current app search only shows gateway controller helper names `callerRoleNotAllowed`, which are not the old CLI detector.
- Reworked/deleted many old command tests that encoded local DB caller-role detection. Current expanded command-family run is still not green: `php -d memory_limit=512M vendor/bin/pest --compact tests/Feature/Commands/Nodes tests/Feature/Commands/Gateway tests/Feature/Commands/Dns tests/Feature/Commands/Operations tests/Feature/Commands/Workspaces tests/Feature/Commands/NodeUpdateCommandTest.php tests/Feature/Commands/NodeShowCommandTest.php tests/Feature/Commands/NodeListCommandTest.php tests/Feature/Commands/NodeRegisterCommandTest.php` is at 703 passing / 23 failing.
- Remaining known failures are concentrated in `node:revoke` self-lockout semantics, two `node:show` interactive fallback expectations, `DoctorRoleAwareCategoriesTest` non-gateway cases, `profile` control-forwarding, `update:all` renderer expectations that now include the gateway row, and a few workspace forwarded/control renderer cases.
- `vendor/bin/pint --dirty --format agent` was run and fixed formatting across the dirty tree.
- Completed the command-family thin-CLI test rewrites: feature tests now default to gateway-local behavior where they exercise gateway-owned state directly, and forwarding/local-client tests explicitly set `orbit.is_gateway=false`.
- Reworked E2E topology support so control machines do not keep a local `control-1` self-row; gateway-owned node identity remains on the gateway.
- Fixed node docs lint drift that still referenced `Local caller role` and app-node local rejection wording.
- Verification so far: `php -d memory_limit=512M vendor/bin/pest --compact` passes with 2454 tests (2385 passed, 69 skipped). `vendor/bin/pint --dirty --format agent` run after the full suite.
