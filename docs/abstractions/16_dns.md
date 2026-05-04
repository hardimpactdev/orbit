# DNS Implementation Patterns

Read this with `docs/abstractions/cross-cutting.md` before implementing DNS
command ports.

Product behavior remains owned by `docs/commands/16_dns/**` and the top-level
product docs.

## Domain Constraints

- DNS commands are caller-local control-node commands.
- DNS commands must not query or mutate gateway intent, app routes, proxy
  routes, Cloudflare records, public DNS, or gateway-owned development DNS
  mappings.
- `dns:resolve-tld` is the DNS-family write command and owns local resolver
  mutation.
- `dns:list` is read-only and lists Orbit-managed local resolver overrides from
  the same local resolver storage used by `dns:resolve-tld`.
- Missing local node role resolves as `control`; unsupported local role values
  resolve as `unknown` and fail before local resolver reads.
- `dns:list` supports read-only inspection on Linux and macOS because it reads
  Orbit-managed local resolver files, not OS resolver state.
- `dns:resolve-tld` currently mutates only the macOS dnsmasq backend. Linux
  write support stays deferred until that command contract or E2E lane needs it.
- Old Orbit's DNS list path queried a gateway/container dnsmasq config. That is
  legacy evidence only; the current DNS contract is caller-local and must not
  reintroduce gateway or container reads.

## Evidence Pointers

- `docs/commands/16_dns/README.md`
- `docs/commands/16_dns/1_dns-resolve-tld`
- `docs/commands/16_dns/2_dns-list`
- `app/Console/Commands/DnsResolveTldCommand.php`
- `app/Console/Commands/DnsListCommand.php`
- `app/Services/Dns/LocalResolver.php`
- `tests/Feature/Commands/Dns/DnsResolveTldCommandTest.php`
- `tests/Feature/Commands/Dns/DnsListCommandTest.php`
- Old evidence: `../orbit-old-may/app/Console/Commands/DnsListCommand.php`
- Old evidence: `../orbit-old-may/app/Actions/Dns/ListDnsMappings.php`
- Old evidence: `../orbit-old-may/app/Concerns/ReadsDnsmasqConfig.php`
