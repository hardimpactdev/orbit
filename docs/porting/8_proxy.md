# 8_proxy — Proxy Workstream

Detail file for the proxy command family. Top-level status lives in
[`PORTING.md`](PORTING.md). Doc authority: `docs/commands/8_proxy/`.

## Commands

- [x] `proxy:list` — registry read foundation.
- [x] `proxy:add` — custom proxy intent + runtime warnings.
- [x] `proxy:remove` — custom proxy intent removal + runtime warnings.

Pest under `tests/Feature/Commands/Proxy/`. Command-level Docker feature E2E
proves gateway intent writes/reads/removal, JSON shape, warning metadata, and
destructive consent. Backend Caddy/TLS reality remains covered by the family
doctor gates below.

## E2E

- [x] Command-port Docker feature E2E:
  `tests/E2E/ProxyCommandTest.php` for `proxy:add`, `proxy:list`, and
  `proxy:remove`.
  `composer test:e2e:docker -- --filter='writes lists and removes custom proxy intent'`.

## Family doctor

`ProxyRouteProbe` covers registry intent, owner eligibility, node
eligibility, custom-domain conflicts, backend route reality, and TLS reality.
Verify-mode dispatcher integration via `--family=proxy`. Fix map handles
`route_missing`, `route_mismatch`, `tls_missing`, and `tls_mismatch` via
Caddy site reconciliation and Orbit-managed TLS material repair.

- [x] Safe `route_extra` cleanup — `ProxyRouteFixer::removeExtra()` removes
  orphaned Caddy sites and Orbit-managed TLS material from nodes. Pest coverage:
  `tests/Feature/Commands/Operations/DoctorCommandContractTest.php` (verify +
  fix mode). Passed gate:
  `php artisan test --compact --filter='reports proxy route_extra drift'` and
  `php artisan test --compact --filter='lets fix mode remove extra proxy routes'`.
- [x] Custom-route adoption handlers — `ProxyRouteAdopter` classifies observed
  Caddy vhosts as custom proxy/redirect intent, skipping app-owned, workspace,
  internal IP, and unclassifiable routes. Adoption checks global domain
  uniqueness. Pest coverage:
  `tests/Unit/Services/Proxy/ProxyRouteAdopterTest.php` and
  `tests/Feature/Commands/Operations/DoctorCommandContractTest.php` (adopt mode).
  Passed gate:
  `php artisan test --compact --filter='lets adopt mode create custom proxy intent'`.

## Activity backfill

- [x] Author `## Activity Logging` sections for `proxy:list`, `proxy:add`,
  `proxy:remove`, then add them to
  `ActivityLoggingContractRule::ENFORCED_COMMANDS`. `composer docs-lint`
  passes.
