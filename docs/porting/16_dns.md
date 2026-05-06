# 16_dns — DNS Workstream

Detail file for the DNS command family. Top-level command status lives in
[`PORTING.md`](PORTING.md). Doc authority: `docs/commands/16_dns/`.

## Workstream

- [x] Convert DNS command docs into current format.
- [x] Port `dns:resolve-tld`.
  - Current implementation: `app/Console/Commands/DnsResolveTldCommand.php`
  - Current service: `app/Services/Dns/LocalResolver.php`
  - Current tests:
    - `tests/Feature/Commands/Dns/DnsResolveTldCommandTest.php` (base contract, caller role, validation, idempotence, unsupported platform, safety)
    - `tests/Feature/Commands/Dns/DnsResolveTldNonInteractiveInputModeTest.php` (non-interactive mode, missing input, forbidden input, invalid values)
    - `tests/Feature/Commands/Dns/DnsResolveTldJsonRendererTest.php` (JSON envelope, success/error shapes, every error code, refresh-failed partial data)
    - `tests/Feature/Commands/Dns/DnsResolveTldHumanRendererTest.php` (human progress trees, success/failure prose, no JSON envelopes)
    - `tests/E2E/DnsResolveTldTest.php` (Docker feature E2E for control-node resolver write/reset).
  - Contract gaps:
    - Interactive input mode prompts are covered by command logic but not fully exercised via automated TTY prompts (standard PHPUnit/Pest limitation).
    - Linux backend support is intentionally deferred; only macOS dnsmasq backend is implemented.
- [x] Port `dns:list`.
  - Current implementation: `app/Console/Commands/DnsListCommand.php`
  - Current service: `app/Services/Dns/LocalResolver.php`
  - Current tests:
    - `tests/Feature/Commands/Dns/DnsListCommandTest.php` (base contract, caller role, local resolver read behavior, empty result success, unsupported platform, safety)
    - `tests/Feature/Commands/Dns/DnsListJsonRendererTest.php` (JSON envelope, success metadata, empty result shape, resolver entry shape, error envelopes)
    - `tests/Feature/Commands/Dns/DnsListHumanRendererTest.php` (human table, empty result prose, failure prose, no progress tree, no JSON envelopes)
    - `tests/E2E/DnsListTest.php` (Incus-backed Linux control-node feature gate)
  - Old evidence:
    - `../orbit-old-may/app/Console/Commands/DnsListCommand.php`
    - `../orbit-old-may/app/Actions/Dns/ListDnsMappings.php`
    - `../orbit-old-may/app/Concerns/ReadsDnsmasqConfig.php`
  - Implemented: control-only caller-role gate, read-only local
    resolver listing from Orbit-managed dnsmasq override files, JSON renderer,
    human renderer, empty-result success, unsupported-platform failure, and
    resolver-read failure.
  - Product decision: current `dns:list` follows the clean DNS contract and
    reads caller-local resolver overrides. It does not port old Orbit's
    gateway/container DNS query path because current DNS docs explicitly keep
    this command local and away from gateway-owned development DNS mappings.
  - Verification:
    - In-memory Pest: `php artisan test --compact tests/Feature/Commands/Dns`.
    - Feature E2E: `composer test:e2e -- --filter='DnsList'`.
      This gate installs the current checkout into the disposable control role
      and invokes `php artisan dns:list --json` from that checkout, leaving the
      baked `orbit` symlink and reusable topology baselines unchanged.
