# 8_proxy — Proxy Workstream

Detail file for the proxy command family. Top-level command status lives in
[`PORTING.md`](PORTING.md). Doc authority: `docs/commands/8_proxy/`.

## Commands and intent foundation

- [~] Port proxy route family.
  - [x] Proxy abstraction seed exists at `docs/abstractions/8_proxy.md`.
  - [x] Proxy read foundation and `proxy:list`.
  - [x] Custom proxy add/remove intent and runtime warnings.

## Family doctor

- [~] Proxy doctor probes, fix/adopt map, and live backend/TLS inspection.
  - [x] Registry intent, owner eligibility, node eligibility, and custom-domain conflict probe foundation.
  - [x] Backend route and TLS reality inspection.
  - [~] Fix/adopt map and doctor dispatcher/API integration.
    - [x] Verify-mode doctor dispatcher/API integration is ported for
      `--family=proxy`.
    - [x] Generic `--fix` / `--adopt` orchestration now reaches family
      dispatch and records unsupported actions as skipped.
    - [x] Fix map handles custom `proxy.route_missing` and
      `proxy.route_mismatch` through Caddy site reconciliation.
    - [x] Fix map handles custom `proxy.tls_missing` and
      `proxy.tls_mismatch` through Orbit-managed TLS material repair.
    - [!] Safe `proxy.route_extra` cleanup and custom-route adoption action
      handlers remain outstanding.
    - Next concrete action: define selected-route adoption scope for
      `proxy.route_extra` / compatible `proxy.route_mismatch`, then add the
      adoption action handler.

## Activity backfill

- [!] Activity backfill is blocked until `docs/abstractions/8_proxy.md`
  exists and a clean `proxy:*` command surface is implemented.
