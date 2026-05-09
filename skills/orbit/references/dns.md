# Local DNS Commands

Caller-local Orbit DNS resolver overrides. Used on a control node so wildcard development hostnames under a TLD resolve to the development app node's WireGuard address. Spec: [`docs/commands/16_dns/`](../../../docs/commands/16_dns/).

Public DNS for production apps is **not** managed here — Orbit uses Cloudflare integration (when configured) for that.

Supported local resolver platforms: macOS (`dnsmasq` via Homebrew, or `/etc/resolver`) and Ubuntu (`systemd-resolved`).

## `orbit dns:list`

List caller-local resolver overrides.

```bash
orbit dns:list [--json]
```

## `orbit dns:resolve-tld [tld] [target]`

Configure or remove a development TLD resolver override.

```bash
orbit dns:resolve-tld [<tld>] [<target>] [--reset] [--force] [--json]
```

| Option | Notes |
|---|---|
| `tld` | TLD without leading dot (e.g. `beast`). |
| `target` | IP that wildcard hostnames under the TLD should resolve to (typically the app node's WireGuard IP, e.g. `10.6.0.20`). |
| `--reset` | Remove the override for `tld`. |
| `--force` | Skip confirmation when overwriting or removing. |

Examples:

```bash
orbit dns:resolve-tld beast 10.6.0.20         # resolve *.beast → 10.6.0.20
orbit dns:resolve-tld beast --reset --force   # remove the override
orbit dns:list --json
```

When you provision a development app node with `node:new --role=app --tld=…`, the gateway records the development DNS mapping; `dns:resolve-tld` is the local-resolver flip on a control node so that mapping actually resolves through the OS.
