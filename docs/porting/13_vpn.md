# 13_vpn — VPN Workstream

Detail file for the VPN command family. Top-level command status lives in
[`PORTING.md`](PORTING.md). Doc authority: `docs/commands/13_vpn/`.

## Status

Ported. The VPN command contracts have gateway-local service abstractions,
in-memory command/service coverage, paired Docker feature E2E coverage for
gateway-local execution and control-caller forwarding with a faked wg-easy
backend, activity logging for the command/API surfaces, and a production
`WgEasyVpnBackend::changeWebUiPassword` adapter for the current gateway-local
wg-easy database and `.env` credential convention.

## Commands

- [x] `vpn-client:list` — Pest coverage:
  `tests/Feature/Commands/Vpn/VpnClientCommandTest.php`,
  `tests/Feature/Http/Api/VpnControllerActivityTest.php`, and
  `tests/E2E/VpnCommandTest.php`.
- [x] `vpn-client:new` — Pest coverage:
  `tests/Feature/Commands/Vpn/VpnClientCommandTest.php` and
  `tests/E2E/VpnCommandTest.php`.
- [x] `vpn-client:enable` — Pest coverage:
  `tests/Feature/Commands/Vpn/VpnClientCommandTest.php`.
- [x] `vpn-client:disable` — Pest coverage:
  `tests/Feature/Commands/Vpn/VpnClientCommandTest.php`.
- [x] `vpn-client:remove` — Pest coverage:
  `tests/Feature/Commands/Vpn/VpnClientCommandTest.php`.
- [x] `vpn-web-ui:change-password` — Pest coverage:
  `tests/Feature/Commands/Vpn/VpnWebUiChangePasswordCommandTest.php`.
