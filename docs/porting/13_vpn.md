# 13_vpn — VPN Workstream

Detail file for the VPN command family. Top-level command status lives in
[`PORTING.md`](PORTING.md). Doc authority: `docs/commands/13_vpn/`.

## Status

Not started. The family port is blocked until the supporting prerequisites are
in place:

- `docs/abstractions/13_vpn.md` does not yet exist; the abstraction seed must
  be authored before any implementation todo is promoted.
- The activity-logging contract allowlist cannot include `vpn:*` until the
  command surface is implemented.

## Commands

- [ ] `vpn-client-list`
- [ ] `vpn-client-new`
- [ ] `vpn-client-enable`
- [ ] `vpn-client-disable`
- [ ] `vpn-client-remove`
- [ ] `vpn-web-ui-change-password`
