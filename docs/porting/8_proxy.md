# 8_proxy — Proxy Workstream

Detail file for the proxy command family. Top-level status lives in
[`PORTING.md`](PORTING.md). Doc authority: `docs/commands/8_proxy/`.

## Commands

- [x] `proxy:list` — registry read foundation.
- [x] `proxy:add` — custom proxy intent + runtime warnings.
- [x] `proxy:remove` — custom proxy intent removal + runtime warnings.

Pest under `tests/Feature/Commands/Proxy/`. No E2E coverage yet — backend
reality is exercised via the family doctor.

## Family doctor

`ProxyRouteProbe` covers registry intent, owner eligibility, node
eligibility, custom-domain conflicts, backend route reality, and TLS reality.
Verify-mode dispatcher integration via `--family=proxy`. Fix map handles
`route_missing`, `route_mismatch`, `tls_missing`, and `tls_mismatch` via
Caddy site reconciliation and Orbit-managed TLS material repair.

- [~] Safe `route_extra` cleanup and custom-route adoption handlers are
  outstanding implementation work. Keep adoption scoped to explicitly selected
  observed routes that can be represented as custom proxy intent, then prove
  the behavior with Pest plus paired E2E.

## Activity backfill

- [x] Author `## Activity Logging` sections for `proxy:list`, `proxy:add`,
  `proxy:remove`, then add them to
  `ActivityLoggingContractRule::ENFORCED_COMMANDS`. `composer docs-lint`
  passes.
