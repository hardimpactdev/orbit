# 4_firewall — Firewall Workstream

Detail file for the firewall command family. Top-level status lives in
[`PORTING.md`](PORTING.md). Doc authority: `docs/commands/4_firewall/`.

## Commands

- [x] `firewall:list` — registry read foundation.
- [x] `firewall:allow` / `firewall:deny` — intent writes + runtime warnings.
- [x] `firewall:remove` — intent removal + runtime warnings.

Pest under `tests/Feature/Commands/Firewall/`. Command-level Docker feature E2E
proves gateway intent writes/reads/removal, JSON shape, warning metadata, and
destructive consent. Real UFW backend reality remains covered by the Incus
family-doctor gate below.

## E2E

- [x] Command-port Docker feature E2E:
  `tests/E2E/FirewallCommandTest.php` for `firewall:allow`,
  `firewall:deny`, `firewall:list`, and `firewall:remove`.
  `composer test:e2e:docker -- --filter='writes lists and removes firewall intent'`.

## Family doctor

`FirewallRuleProbe` covers registry intent, node eligibility, baseline
policy boundary, and backend UFW reality. Verify-mode dispatcher integration via
`--family=firewall_rule`. Fix map handles `rule_missing` and `rule_mismatch`
through UFW reconciliation. Docker is intentionally not used for real UFW
backend assertions.

- [x] Adopt handlers for selected compatible backend rules. Implemented in
  `FirewallRuleProbe::adopt()`. Adopts observed UFW rules into registry records,
  skips baseline SSH policy, handles name collisions. Pest coverage:
  `tests/Unit/Services/Firewall/FirewallRuleProbeTest.php`. Doctor adopt dispatch
  coverage: `tests/Feature/Commands/Operations/DoctorCommandContractTest.php`;
  Incus VM-feature coverage:
  `tests/E2E/FirewallDoctorAdoptTest.php`;
  `composer test:e2e:incus -- --filter='adopts observed UFW rules into the gateway registry'`.

## Activity backfill

- [x] Author `## Activity Logging` sections for `firewall:list`,
  `firewall:allow`, `firewall:deny`, `firewall:remove`, then add them to
  `ActivityLoggingContractRule::ENFORCED_COMMANDS`. `composer docs-lint`
  passes.
