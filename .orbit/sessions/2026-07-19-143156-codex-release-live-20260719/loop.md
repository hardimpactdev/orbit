# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: codex://current
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-release-live-20260719`
- Branch: `codex/release-live-20260719`

## Goal

Release the accepted main revision to the live topology through a verified current-version candidate, fixing historical runtime ownership, ensuring final fleet verification waits for deferred Agent restarts, and relaying private role-image archives to workload nodes without registry credentials.

## Scope

- Owned: the runtime-ownership migration and focused test; fleet-update Agent restart readiness service, verifier integration, configuration, focused tests, and product documentation; private role-image artifact relay, typed CLI payload and installer, focused tests and product documentation; candidate artifacts; and live release evidence.
- Constraints: preserve historical setup-run history; prove ownership from existing logical app node/path; keep pre-existing doctor drift unchanged; no GitHub release, Git tag, or stable image promotion.
- Out of scope: unrelated doctor drift, app/runtime cleanup, deployment ownership, and topology repair.

## Proof

- Verification:
  - focused: passed - runtime-ownership coverage went red on ambiguous placement, then 78 migration/release tests and 585 assertions passed; Agent restart ordering and typed readiness failure tests went red then green, with the final verifier suite passing 10 tests and 50 assertions and the focused operations suite passing 37 tests and 289 assertions. The private role-image relay and installer were developed red-to-green; the final gateway slice passed 43 tests and 319 assertions, the combined CLI slice passed 65 tests and 389 assertions, and exact core targets passed 17 install tests with 113 assertions plus 23 updater tests with 203 assertions. Root-cause evidence is retained in `.orbit/evidence/release-migration-provenance.json`, `.orbit/evidence/release-first-update-failed.jsonl`, and the transient live update activity streams.
  - broader: passed - `ORBIT_QUALITY_CHECK_CPU_BUDGET=2 composer quality-check` exited 0 at exact clean tip `589411b172c47dd246957f3152e27a36f001e9ce`; every subgate is 0 in `.orbit/quality-gates/quality-check-2026-07-19T121027Z-cef1ded3fd63.json`. One earlier CPU-budget-4 run ended only in SIGKILL-class CLI contention; the exact full CLI suite then exited 0 independently before the controlled aggregate rerun.
  - runtime: passed - retained topology `dev-faabdf` on `beast` matched exact reviewed gateway and CLI hashes, exposed Docker server 29.1.3 on the Linux workload, passed the hash-verified role-image archive loader test with 5 assertions, and passed the gateway artifact-relay payload test with 48 assertions; evidence is `.orbit/evidence/retained-incus-private-role-image-artifact.txt`. Live candidate `20260719T121607Z-589411b17` from exact pushed source `589411b172c47dd246957f3152e27a36f001e9ce` completed operation `664a79bb-72b7-4b73-8422-53b48a790894`: activity 210653 proves the hash-addressed private WebSocket archive loaded on `services1`, activity 210697 verifies the exact digest locally, and activity 210698 records the completed fleet update with gateway digest `sha256:4c35481f361f5e8f55eed30f5fd9df3becc5840a6a25647440e6dfdaf40d1e2c`. All nine nodes remain active; non-NMBP Doctor issue identities are unchanged, while NMBP recovered from one probe failure to expose eight pre-existing underlying issues. Full live evidence is `.orbit/evidence/release-private-role-image-live.txt`.
- Blast radius: complete - evidence=independent base-to-tip review of the typed payload, shell transport, gateway role selection, release manifest artifact contract, tests, and documentation; result=no unresolved caller, platform, validation, or private-registry fallback gaps
- Review: passed - independent exact-tip reviewer found no findings; human-judgment=not-required
- Reviewed feature tip: 589411b172c47dd246957f3152e27a36f001e9ce
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 589411b172c47dd246957f3152e27a36f001e9ce
- Accepted main tip: 5588ef2ed280e46f660572c2718e5c6e42c88747

## Status

- State: land
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
