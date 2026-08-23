candidate=060b6fce0cf96e232c046c6e3b121f7bed4212f2

# impl-harness handoff

Ordinary feature-loop corrections for watch ack identity, atomic handoff,
one-command automated acceptance, compact skill proof identity, focused Mago
contract, compact archive diagnostics, and conditional FRAME inventory.

## Inventory

| Outcome | Producers | Consumers | Dangerous invariants |
| --- | --- | --- | --- |
| Revision-sensitive watch ack | `bin/orbit-worker-watch` snapshots; heartbeat note/heartbeat_at/log mtime | owner `--ack=` re-arm | Unchanged blocked/stale stay suppressed; handoff still path+size+mtime; exited still `exited_at` |
| Atomic handoff | `bin/orbit-worker-handoff --note`; heartbeat working/blocked only | HARNESS, implementing-features, worker tests | Impl still requires `candidate=<40-character sha>` and SHA-bound proof receipt; SHA-keyed files; `--force` overwrite |
| One-command automated accept | `bin/orbit-feature-acceptance accept --actor=automated` derives venue | ready still arms delayed human accept | TOCTOU, clean tree, feedback, proof receipt, review identity, main inclusion, venue, human-judgment remain fail-closed |
| Compact skill identity | implementing-features, HARNESS | McpConfigurationTest, workers | Skill ≤6720B; combined contract cap; Grok medium / Opus high launchers |
| Focused Mago | HARNESS PROVE, implementing-features | impl workers | No extra mago when no production PHP changed |
| Compact archive diagnostics | `bin/orbit-session-archive` compact copy | LAND, cleanup, receipt allowlist | Historical v2/v3 readable; no worker logs or agent transcripts; secrets still scanned |
| Conditional FRAME | HARNESS FRAME, implementing-features FRAME | owners before dispatch | Inventory not required for ordinary local changes |

The invalid historical `bin/orbit-feature-feedback relevant` event was treated as observed context only.

## RED

`bin/orbit-gateway-pest --compact --filter='heartbeat rejects terminal|stores the final note atomically|watch acknowledgements of blocked|watch acknowledgements of stale|documents the default watch interval and ack targeting|validates and records an automated candidate|keeps delayed human acceptance|retains structured worker handoffs|keeps the orchestrating session in charge|keeps HARNESS canonical with a compact|keeps FRAME inventory and focused Mago'`

Result: 11 tests, 1 passed (delayed human arm/accept already existed), 10 failed. Failures were missing note on handoff, blocked/stale snapshots ignoring later revisions, heartbeat still accepting `--status=handoff`, automated accept refusing weaker seeded venue, compact archives omitting worker/failed-gate files, and missing contract sentences.

## GREEN

- Same focused filter after implementation: 13 passed.
- `bin/orbit-gateway-pest --compact tests/Feature/Architecture/McpConfigurationTest.php tests/Feature/E2ESupport/FeatureFinalizationGateTest.php`: 216 passed.
- Related WorkerTools/FeatureAcceptance/SessionArchive/ProofReceipt/SessionIndex files passed aside from later-fixed skill timing/format items.
- Focused Mago: `php -l` on each changed `bin/` PHP file; `bin/orbit-gateway-vendor-bin mago lint --semantics` on those files: no issues. No broad mago rerun.
- Terminal `composer quality-check` on `060b6fce0cf96e232c046c6e3b121f7bed4212f2`: passed. Not rerun after success.
- `bin/orbit-feature-proof-receipt --json`: ok, candidate `060b6fce0cf96e232c046c6e3b121f7bed4212f2`, gate quality-check, venue automated, artifact `.orbit/quality-gates/quality-check-2026-08-23T085007Z-d224f82345bf.json`.

## Changed files

- `HARNESS.md`
- `.agents/skills/implementing-features/SKILL.md`
- `bin/orbit-worker-watch`
- `bin/orbit-worker-handoff`
- `bin/orbit-worker-heartbeat`
- `bin/orbit-worker-registry.php`
- `bin/orbit-feature-acceptance`
- `bin/orbit-session-archive`
- `bin/orbit-session-archive-receipt.php`
- `apps/gateway/tests/Feature/E2ESupport/WorkerToolsTest.php`
- `apps/gateway/tests/Feature/E2ESupport/FeatureAcceptanceTest.php`
- `apps/gateway/tests/Feature/E2ESupport/SessionArchiveTest.php`
- `apps/gateway/tests/Feature/Architecture/McpConfigurationTest.php`

## Remaining risk

POLISH: gateway mago lint against root `bin/` files (outside the gateway source map) still reports pre-existing complexity/style noise; this candidate used semantics + `php -l` as the focused check. No DEFECT remaining for the requested outcomes.

Worktree dirty: none at handoff. Workers never merge.
