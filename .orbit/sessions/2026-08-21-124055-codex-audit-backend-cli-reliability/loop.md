# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: solo://proj/122/scratchpad/backend-and-cli-reli--527
- Worktree: /Users/nckrtl/orbit/.worktrees/codex-audit-backend-cli-reliability
- Branch: codex/audit-backend-cli-reliability

## Goal

Apply the approved backend/CLI reliability audit slice: fix the update-lease,
installer, firewall, and DNS correctness/security drift, and remove the eight
verified dead production surfaces (plus their self-only tests and baseline
entries), while preserving every live public contract, error code, JSON
envelope, and visible CLI output.

## Scope

- Owned:
  - Correctness/security: `apps/gateway/app/Services/Operations/UpdateLease*`
    (S16-OPS-1/2), installer wg-easy predicate (S01-INSTALL-1),
    UFW firewall apply/delete parity (S23-FIREWALL-1),
    `apps/cli/app/Services/Dns/LocalResolver.php` duplicate write/flush
    (S23-DNS-1).
  - Dead surfaces: `bin/quality-check.sh` unused label arrays (S03-QG-1),
    controller `activityLog*` alias methods (S11-HTTP-1),
    `StreamsGatewayProgress::captureProgressTerminalFrame()` (S21-STREAM-1),
    `LocalUpdateWorkflow` + `pullSource`/`installDependencies`/`runMigrations`
    interface surface (S24-UPDATE-1), `OrbitConfigStoreImporter` (S25-CONFIG-1),
    gateway `WireGuardGatewayAddressResolver` (S29-NET-1),
    `UpdateDriver::supportedTargets()` + `UpdateDriverTarget` (S30-UPDATES-1),
    dead classifier helpers in `bin/orbit-command-classify.php` (S33-CLASSIFY-1).
  - Self-only tests and Mago baseline entries that only preserve a removed
    dead contract.
- Constraints:
  - TDD (RED before GREEN) for every behavior change; verify no live caller
    before every deletion.
  - Preserve `Loggable`/`ActivityLogger`, live update binary flow, the CLI
    WireGuard resolver, `supports(UpdateTarget)`, and the live classifier
    helpers.
  - Lease ownership uses `hash_equals`; token values never logged/returned.
    Model deactivation stays persistence-free; services decide and save.
  - Never run/delegate/trigger any `composer test:e2e*` command.
- Out of scope: S13 node retry guard, S06 PHP SDK SSE rewrite, S19 Vite/Tailwind
  removal, S18 legacy-cleanup abstraction, TypeScript SDK/docs/Orbit skill
  (separate loop), any refactor beyond the named findings.

## Proof

- Candidate: 6db5ad6d17fcea9b6dfbfe06b939cb12ed9e14fc
- Verification:
  - focused: passed - gateway 353 tests/2149 assertions (lease, firewall,
    driver-registry, LogActivity, install-first, verification-scripts) +
    UpdateLease model unit; cli 272 tests/1477 assertions (dns, updates,
    operation commands); S33 classifier hook test PASS. Orphan sweep clean for
    every deleted symbol; kept surfaces (CLI WireGuard resolver, live
    classifier helpers, supports(UpdateTarget), live RunsLocalUpdate methods)
    all present.
  - broader: passed - composer quality-check exit 0, 45/45 subgates green,
    bound to HEAD 6db5ad6d (clean); artifact
    `.orbit/quality-gates/quality-check-2026-08-21T102004Z-fdb3f7bb64b8.json`
  - runtime: passed - candidate=6db5ad6d17fcea9b6dfbfe06b939cb12ed9e14fc; venue=retained-incus; environment=dev-fixture; command=orbit doctor --node=app-dev-1 --family=process --json plus candidate-bound lease installer and UFW exercises; expected=the mounted candidate keeps gateway and CLI healthy while lease owner rejection heartbeat idempotent release installer wg-easy-only forwarding and family-normalized UFW apply-delete complete and clean up; observed=topology dev-9422d7 returned healthy Doctor JSON with zero issues and HTTP 200 then lease installer and UFW proofs passed with proof rows temporary files and rule removed; result=passed; evidence=`.orbit/evidence/audit-backend-cli-retained-incus-receipt.md`
- Required verification:
  - Retained topology proof: passed - topology id=dev-9422d7; kind=operator_gateway_app-dev; host=beast; inspected roles=gateway,dev; candidate digests matched the source-mounted checkout; Solo VM terminal=2642; evidence=`.orbit/evidence/audit-backend-cli-retained-incus-receipt.md`
  - `composer quality-check`: passed - exact clean candidate 6db5ad6d17fcea9b6dfbfe06b939cb12ed9e14fc; dirty=false; exit=0; all 45 subgates zero; artifact=`.orbit/quality-gates/quality-check-2026-08-21T102004Z-fdb3f7bb64b8.json`
- Blast radius: complete - evidence=repository-wide inventory of every deleted symbol plus kept-surface presence check; result=zero live callers for all removed surfaces and every kept surface (Loggable canonical methods, supports(UpdateTarget), CLI WireGuard resolver, live RunsLocalUpdate binary flow, _from_words/plural classify helpers) still present
- Review: passed - independent general reviewer, no actionable findings, human-judgment=not-required; retained because the proof-only receipt correction did not change candidate 6db5ad6d
- Reviewed feature tip: 6db5ad6d17fcea9b6dfbfe06b939cb12ed9e14fc
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 6db5ad6d17fcea9b6dfbfe06b939cb12ed9e14fc
- Accepted main tip: b2d41467c8be7a232fff7eca1364f1ebd7627eb9

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
