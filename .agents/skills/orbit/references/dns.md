# Local DNS Commands

Caller-local Orbit DNS resolver overrides. Used on the caller machine so
wildcard development hostnames under a TLD resolve to the target node's
WireGuard or caller-facing private address. Spec:
[`apps/docs/content/domains/15_dns/`](../../../../apps/docs/content/domains/15_dns/).

Public DNS for production apps is **not** managed here  -  Orbit uses Cloudflare integration (when configured) for that.

Supported local resolver platforms: macOS (`dnsmasq` via Homebrew) and Ubuntu
(`systemd-resolved`).

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
| `target` | IP that wildcard hostnames under the TLD should resolve to (typically the target node's WireGuard IP, e.g. `10.6.0.20`, or a configured caller-facing private IPv4). |
| `--reset` | Remove the override for `tld`. |
| `--force` | Skip confirmation when overwriting or removing. |

Examples:

```bash
orbit dns:resolve-tld beast 10.6.0.20         # resolve *.beast -> 10.6.0.20
orbit dns:resolve-tld beast --reset --force   # remove the override
orbit dns:list --json
```

On macOS, Orbit writes per-TLD files under the host user's
`~/.config/orbit/dnsmasq.d`, keeps the Homebrew `dnsmasq.conf` pointed at that
directory, removes stale Orbit-managed entries for the selected TLD, and
preserves operator-owned dnsmasq config. Changed mappings also flush the macOS
system resolver cache and reload `mDNSResponder` so cached answers do not
outlive the override update.

When you provision a development node with `node:new --template=app-development
--tld=...`, the gateway records the development DNS mapping; `dns:resolve-tld`
is the caller-local resolver flip so that mapping actually resolves through the
OS.
