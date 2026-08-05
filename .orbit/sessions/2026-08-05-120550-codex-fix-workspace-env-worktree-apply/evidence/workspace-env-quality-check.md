# Workspace env quality gate

- Candidate: `89322e6f9436dfa35cee08203a30203a6b2a24ce`
- Command: `ORBIT_QUALITY_CHECK_CPU_BUDGET=6 composer quality-check`
- Result: passed
- Profile: `.orbit/quality-gates/profiles/2026-08-05T09-58-57Z-89322e6f9436`
- CLI Pest: 2,434 tests, 10,146 assertions, all five shards passed
- Gateway Pest: 5,660 tests, 34,536 assertions passed
- Docs Pest: 176 tests, 11,562 assertions passed
- Core and SDK Pest lanes passed
- The remaining quality-check lanes, including Mago, Rector, docs linting, and Rust checks, completed successfully.
