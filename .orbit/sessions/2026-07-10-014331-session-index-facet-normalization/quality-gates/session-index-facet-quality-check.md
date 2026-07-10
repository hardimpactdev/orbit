# Session index facet normalization quality gate

- Checkout: `/Users/nckrtl/orbit/.worktrees/session-index-facet-normalization`
- Branch: `session-index-facet-normalization`
- Command: `composer quality-check`
- Result: passed three times (exit 0); cold 101s, same-command warm comparison 81s, exact committed tree 79s
- Exact commit: `88c86af1538e035c030337b104e485eac5ff7a51`
- Exact-tree artifact: `.orbit/quality-gates/quality-check-2026-07-09T234213Z-c4740726bb03.json`
- Gateway Pest: 4400 passed, 25275 assertions
- CLI Pest: 2171 passed, 9089 assertions
- Docs Pest: 128 passed, 1034 assertions
- Core Pest: 111 passed, 517 assertions
- SDK Pest: 128 passed, 411 assertions
- Remaining lanes: docs-lint, Mago, Rector, E2E-app unit checks, Reverb, Agent Cargo, and macOS Cargo passed
- Timing classification: stale/missing June baseline with cold-cache and host/environment contribution; see `.orbit/evidence/session-index-quality-gate-triage.md`
- Note: no manual-only `composer test:e2e*` lane was invoked.
