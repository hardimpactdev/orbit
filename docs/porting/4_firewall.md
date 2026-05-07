# 4_firewall — Firewall Workstream

Detail file for the firewall command family. Top-level status lives in
[`PORTING.md`](PORTING.md). Doc authority: `docs/commands/4_firewall/`.

## Commands

- [x] `firewall:list` — registry read foundation.
- [x] `firewall:allow` / `firewall:deny` — intent writes + runtime warnings.
- [x] `firewall:remove` — intent removal + runtime warnings.

Pest under `tests/Feature/Commands/Firewall/`. E2E coverage via Incus VM-feature
lane: `composer test:e2e:incus -- --filter='adopts observed UFW rules into the gateway registry'`
(`tests/E2E/FirewallDoctorAdoptTest.php`).

## Family doctor

`FirewallRuleProbe` covers registry intent, node eligibility, baseline
policy boundary, and backend UFW reality. Verify-mode dispatcher integration
via `--family=firewall_rule`. Fix map handles `rule_missing` and
`rule_mismatch` through UFW reconciliation.

- [x] Adopt handlers for selected compatible backend rules. Implemented in
  `FirewallRuleProbe::adopt()`. Adopts observed UFW rules into registry records,
  skips baseline SSH policy, handles name collisions. Pest coverage:
  `tests/Unit/Services/Firewall/FirewallRuleProbeTest.php`. Doctor adopt dispatch
  coverage: `tests/Feature/Commands/Operations/DoctorCommandContractTest.php`.
  Incus VM-feature E2E: `tests/E2E/FirewallDoctorAdoptTest.php` (re-enabled after
  infrastructure fix for topology SSH connectivity in commit ea7d810).

## Activity backfill

- [x] Author `## Activity Logging` sections for `firewall:list`,
  `firewall:allow`, `firewall:deny`, `firewall:remove`, then add them to
  `ActivityLoggingContractRule::ENFORCED_COMMANDS`. `composer docs-lint`
  passes.
