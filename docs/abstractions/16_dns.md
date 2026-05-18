# DNS Implementation Patterns

Read this with `docs/abstractions/cross-cutting.md` before implementing DNS
command ports.

Product behavior remains owned by `docs/commands/16_dns/**` and the top-level
product docs.

## Layer Separation

DNS in Orbit lives at two layers that must not be confused:

- **Caller-local DNS commands** (`dns:resolve-tld`, `dns:list`) — operator-node
  commands that mutate or read the local OS resolver only. They are the
  product surface this file documents.
- **Gateway-side DNS infrastructure** (`wg-easy` + `orbit-dns` + `dnsmasq.conf`)
  — runtime VPN-served DNS owned by the **tool family** and **bootstrap**,
  specified in
  [`docs/commands/3_tool/dns-bootstrap-contract.md`](../commands/3_tool/dns-bootstrap-contract.md).
  DNS *commands* must not call into that layer. That layer must not call into
  DNS commands.

## Domain Constraints (caller-local commands)

- DNS commands are caller-local operator-node commands.
- DNS commands must not query or mutate gateway intent, app routes, proxy
  routes, Cloudflare records, public DNS, or the development DNS mappings
  owned by the gateway.
- DNS commands must not read or write the gateway-side `dnsmasq.conf` (owned
  by the bootstrap contract above). That layer reaches the WG client by
  serving DNS on the wg-easy VPN address; it is not in scope for the DNS
  command family.
- `dns:resolve-tld` is the DNS-family write command and owns local resolver
  mutation.
- `dns:list` is read-only and lists the local resolver overrides that Orbit
  manages, reading from the same local resolver storage used by `dns:resolve-tld`.
- Missing local node role resolves as `control`; unsupported local role values
  resolve as `unknown` and fail before local resolver reads.
- `dns:list` supports read-only inspection on Linux and macOS because it reads
  the local resolver files that Orbit manages, not OS resolver state.
- `dns:resolve-tld` currently mutates only the macOS dnsmasq backend. Linux
  write support stays deferred until that command contract or E2E lane needs it.
- Old Orbit's DNS list path queried a gateway/container dnsmasq config. That is
  legacy evidence only; the current DNS *command* contract is caller-local and
  must not reintroduce gateway or container reads. Gateway-side dnsmasq reads
  are valid only inside the bootstrap contract layer.

## Evidence Pointers

- `docs/commands/16_dns/README.md`
- `docs/commands/16_dns/1_dns-resolve-tld`
- `docs/commands/16_dns/2_dns-list`
- `docs/commands/3_tool/dns-bootstrap-contract.md` (gateway DNS layer, out of scope here)
- `app/Console/Commands/DnsResolveTldCommand.php`
- `app/Console/Commands/DnsListCommand.php`
- `app/Services/Dns/LocalResolver.php`
- `tests/Feature/Commands/Dns/DnsResolveTldCommandTest.php`
- `tests/Feature/Commands/Dns/DnsListCommandTest.php`
- Old evidence: `../orbit-old-may/app/Console/Commands/DnsListCommand.php`
- Old evidence: `../orbit-old-may/app/Actions/Dns/ListDnsMappings.php`
- Old evidence: `../orbit-old-may/app/Concerns/ReadsDnsmasqConfig.php`
