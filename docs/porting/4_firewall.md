# 4_firewall — Firewall Workstream

Detail file for the firewall command family. Top-level command status lives in
[`PORTING.md`](PORTING.md). Doc authority: `docs/commands/4_firewall/`.

## Commands and intent foundation

- [x] Firewall abstraction seed exists at `docs/abstractions/4_firewall.md`.
- [x] Firewall read foundation and `firewall:list`.
- [x] Firewall allow/deny/remove intent and runtime warnings.

## Family doctor

- [~] Firewall doctor probes, fix/adopt map, and live backend inspection.
  - [x] Registry intent, node eligibility, and baseline policy boundary probe foundation.
  - [x] Backend UFW rule reality inspection.
  - [~] Fix/adopt map and doctor dispatcher/API integration.
    - [x] Verify-mode doctor dispatcher/API integration is ported for
      `--family=firewall_rule`.
    - [x] Generic `--fix` / `--adopt` orchestration now reaches family dispatch
      and records unsupported actions as skipped.
    - [x] Fix map handles `firewall_rule.rule_missing` and
      `firewall_rule.rule_mismatch` through UFW reconciliation.
    - [!] Adopt map for selected compatible backend rules remains outstanding.
      The current global doctor input contract has no explicit selected
      observed-backend-rule scope, so adoption needs a scoped input decision
      before implementation.
    - Next concrete action: define selected-rule adoption scope for
      `firewall_rule.rule_extra` / compatible `firewall_rule.rule_mismatch`,
      then add the adoption action handler.

## Activity backfill

- [!] Activity backfill is blocked until `docs/abstractions/4_firewall.md`
  exists and a clean `firewall:*` command surface is implemented.
