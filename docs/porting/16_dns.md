# 16_dns — DNS Workstream

Detail file for the DNS command family. Top-level status lives in
[`PORTING.md`](PORTING.md). Doc authority: `docs/commands/16_dns/`.

DNS commands are control-only and operate on caller-local resolver
overrides. The product contract intentionally keeps this family away from
gateway-owned development DNS mappings; there is no DNS family doctor.

## Commands

- [x] `dns:resolve-tld` — control-only macOS dnsmasq backend, JSON/human
  renderers, validation, idempotence, unsupported-platform failure. Pest
  under `tests/Feature/Commands/Dns/DnsResolveTld*`; Docker feature E2E
  `tests/E2E/DnsResolveTldTest.php`. Linux backend intentionally deferred.
- [x] `dns:list` — control-only local resolver read from Orbit-managed
  dnsmasq override files. Pest under `tests/Feature/Commands/Dns/DnsList*`;
  Docker feature E2E `tests/E2E/DnsListTest.php`.
