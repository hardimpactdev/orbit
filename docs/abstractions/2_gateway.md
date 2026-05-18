# Gateway Implementation Patterns

Read this with `docs/abstractions/cross-cutting.md` before implementing gateway
command ports.

Product behavior remains owned by `docs/domains/2_gateway/**` and the top-level
product docs.

## Domain Constraints

- Gateway commands manage caller-local gateway relationship and trust, not
  gateway node provisioning.
- `LocalGatewaySettings::current()` is the accessor for local gateway settings,
  which are stored as a single row.
- Gateway CA fetch is bootstrap-safe: fetch trust material before relying on
  configured CA verification.
- Trust installation goes through `TrustStoreInstaller` and OS-specific
  implementations.
- Gateway commands must not create node rows, WireGuard peers, or grants.

## Evidence Pointers

- `docs/domains/2_gateway/README.md`
- `app/Models/LocalGatewaySettings.php`
- `app/Services/Gateway/FetchGatewayRootCa.php`
- `app/Services/Trust/TrustStoreInstaller.php`
- `app/Services/Trust/MacOsTrustStoreInstaller.php`
- `app/Services/Trust/LinuxTrustStoreInstaller.php`
- `app/Console/Commands/GatewayAddCommand.php`
- `app/Console/Commands/GatewayTrustCommand.php`
