# VPN Implementation Patterns

Read this with `docs/abstractions/cross-cutting.md` before implementing VPN
command ports.

Product behavior remains owned by `docs/domains/13_vpn/**` and the top-level
product docs.

## Domain Constraints

- VPN is a gateway infrastructure command domain, not a state family.
- VPN commands administer WireGuard backend clients that run on the gateway for
  human/operator access.
- Orbit node WireGuard identity remains owned by the node family.
- `vpn-client:*` writes must not mutate peers classified as active Orbit
  nodes.
- Operator callers may initiate VPN commands only through the documented
  gateway infrastructure execution exception. This does not create a generic
  operator-to-gateway SSH pattern for other families.
- App callers are denied before prompts or side effects.
- Backend implementation details such as wg-easy API paths or storage layout
  are not product surface.
- There is no `doctor --family=vpn`.

## Backend Pattern

- Keep backend administration behind a gateway-local service/action layer.
- Classify backend peers as `admin`, `node`, or `unknown` before rendering or
  mutating them.
- Treat backend TOTP as backend-administration input only. It is not Orbit
  gateway authorization or destructive consent.
- Never print VPN web UI passwords, backend credentials, or generated private
  keys except the explicitly requested client config payload.

## Command Pattern

- `vpn-client:list` may show all visible backend peers with safe
  classification.
- `vpn-client:new` creates non-node admin clients and may return a generated
  WireGuard config when requested.
- `vpn-client:enable`, `vpn-client:disable`, and `vpn-client:remove` operate
  only on mutable admin clients.
- `vpn-web-ui:change-password` rotates the backend admin credential and stores
  the new gateway-local credential for later VPN operations.
- Operator callers forward through the gateway-specific infrastructure path;
  gateway callers use local backend services.

## E2E Pattern

- Use Docker feature E2E with a faked backend service/API for command contract
  and forwarding coverage.
- Use Incus VM-feature only when a test intentionally validates real WireGuard
  host behavior.

## Evidence Pointers

- `docs/domains/13_vpn/README.md`
- `docs/domains/13_vpn/vpn-concepts.md`
- `docs/domains/13_vpn/1_vpn-client-list`
- `docs/domains/13_vpn/2_vpn-client-new`
- `docs/domains/13_vpn/3_vpn-client-enable`
- `docs/domains/13_vpn/4_vpn-client-disable`
- `docs/domains/13_vpn/5_vpn-client-remove`
- `docs/domains/13_vpn/6_vpn-web-ui-change-password`
- `docs/abstractions/cross-cutting.md`
- Old evidence: `../orbit-old-may/app/Actions/Vpn`
- Old evidence: `../orbit-old-may/app/Console/Commands/VpnListCommand.php`
- Old evidence: `../orbit-old-may/app/Console/Commands/VpnClientNewCommand.php`
- Old evidence: `../orbit-old-may/app/Http/Controllers/Api/VpnClientListController.php`
- Old evidence: `../orbit-old-may/tests/Feature/VpnListCommandTest.php`
- Old evidence: `../orbit-old-may/tests/Feature/Http/VpnClientWriteRoutesTest.php`
