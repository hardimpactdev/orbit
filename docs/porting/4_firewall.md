# 4_firewall — Firewall Workstream

Detail file for the firewall command family. Top-level status lives in
[`PORTING.md`](PORTING.md). Doc authority: `docs/commands/4_firewall/`.

## Commands

- [x] `firewall:list` — registry read foundation.
- [x] `firewall:allow` / `firewall:deny` — intent writes + runtime warnings.
- [x] `firewall:remove` — intent removal + runtime warnings.

Pest under `tests/Feature/Commands/Firewall/`. No E2E coverage yet — the
backend reality is exercised via the family doctor.

## Family doctor

`FirewallRuleProbe` covers registry intent, node eligibility, baseline
policy boundary, and backend UFW reality. Verify-mode dispatcher integration
via `--family=firewall_rule`. Fix map handles `rule_missing` and
`rule_mismatch` through UFW reconciliation.

- [!] Adopt handlers for selected compatible backend rules remain
  outstanding. The global doctor input contract has no explicit selected
  observed-backend-rule scope yet; adoption needs that scope decision first.

## Activity backfill

- [ ] Author `## Activity Logging` sections for `firewall:list`,
  `firewall:allow`, `firewall:deny`, `firewall:remove`, then add them to
  `ActivityLoggingContractRule::ENFORCED_COMMANDS`. Abstraction seed
  (`docs/abstractions/4_firewall.md`) and command surface are already in
  place.
