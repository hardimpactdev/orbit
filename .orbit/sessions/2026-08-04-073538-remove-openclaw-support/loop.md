# Orbit Feature Loop

- Scratchpad: none
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-remove-openclaw-support`
- Branch: `codex/remove-openclaw-support`

## Goal

Remove first-party OpenClaw support so Hermes is the only autonomous agent tool on the `agent` role; keep Hermes installable/updateable to the official latest channel; fix tool-owned proxy lifecycle so supported tool removal and missing tool-owner detection clean or classify orphan proxy routes generically; and ship removal-only `tool:remove openclaw` legacy runtime cleanup (privileged `sudo ss`, hard-coded targets, verified success) via a no-GitHub 0.1.190 candidate refresh and live fleet cleanup.

## Scope

- Owned:
  - `PRODUCT_DECISIONS.md` (append-only intent)
  - `apps/docs/content/**` agent/tool/proxy authority (OpenClaw withdrawn; Hermes latest)
  - `.agents/skills/orbit/references/tool.md`
  - gateway tool catalog/registration, OpenClawTool removal, Hermes retention
  - generic tool-owned proxy cleanup/orphan classification (ToolRemover, ProxyRouteIntent, ProxyRouteProbe)
  - `LegacyOpenClawRuntimeCleanup` removal-only migration (hard-coded agent/home/port 18789; `sudo ss`; verified absence; no env override seam)
  - focused CLI/gateway/SDK tests and PATH-stub cleanup harness
  - no-GitHub live candidate build/activate/update:all for VERSION 0.1.190 and live `tool:remove openclaw`
- Constraints:
  - preserve unrelated user work outside this worktree
  - never run `composer test:e2e*`
  - no GitHub release, Git tag, final GHCR version tag, or split-repo publication
  - no SSH/manual gateway DB edits
  - no VERSION bump (current-version candidate refresh)
  - historical PRODUCT_DECISIONS / sessions / superpowers stay
- Out of scope:
  - GitHub publication / final GHCR version promotion
  - bulk restore of pre-existing/transport doctor drift unrelated to this change

## Proof

- Verification:
  - focused: passed - gateway openclaw/legacy cleanup/proxy suites; PATH-stub harness success + unkillable; hard-coded target assertions (harness proves cleanup script semantics only, not live absence)
  - broader: passed - `composer quality-check` EXIT 0, dirty=false, commit 106cb613fba5c39e8618ce8e053f19546489045d; artifact `.orbit/quality-gates/quality-check-2026-08-03T225911Z-6e1245a61dc4.json`
  - runtime: passed - candidate gateway digest `sha256:97bd0cb4d208509bcf3ec30665e8902c4afc2996301384bf7ef0fb0a415dd6d3` live via update:all activity 414769; `tool:remove openclaw` returned `legacy_runtime_cleanup=true` `routes_removed=1` in `.orbit/evidence/release-0.1.190-openclaw-remove/tool-remove-openclaw.json`; post inventory no openclaw tool/process/proxy in `.orbit/evidence/release-0.1.190-openclaw-remove/post-openclaw-inventory.json`; `https://openclaw.agent` and `http://10.6.0.11:18789` no longer serve OpenClaw Control (connection fail / refused) in `.orbit/evidence/release-0.1.190-openclaw-remove/post-openclaw-urls.txt`; Hermes live `v0.20.0 (2026.8.3)` matches official `v2026.8.3` in `.orbit/evidence/release-0.1.190-openclaw-remove/post-hermes-proof.txt`; post doctor fleet healthy issues=0 in `.orbit/evidence/release-0.1.190-openclaw-remove/post-doctor-all.ndjson` (pre: 22 issues classified pre-existing/transport, not bulk-restored)
- Blast radius: complete - evidence=`rg -n "OpenClaw|openclaw|LegacyOpenClawRuntimeCleanup|ToolRemover|ProxyRouteIntent" apps/gateway apps/cli packages apps/docs/content PRODUCT_DECISIONS.md`; result=OpenClaw product surface withdrawn; removal-only cleanup + Hermes retained; production cleanup targets hard-coded with no env override seam
- Review: passed - human-judgment=not-required; independent reviewer PASS after FIX for env-override seam closed at 106cb613fba5c39e8618ce8e053f19546489045d; reviewer classified old-gateway residual as ops/deploy-only; code acceptable to land
- Reviewed feature tip: 106cb613fba5c39e8618ce8e053f19546489045d
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 106cb613fba5c39e8618ce8e053f19546489045d
- Accepted main tip: fc199f1dff741f4341e9bb26585c69545b7bb38c

## Status

- State: land
- Blocker: none
- Release: VERSION 0.1.190 no-bump candidate refresh; build_id=20260804T052051Z-106cb613f; channel live-test retained; gateway image `ghcr.io/hardimpactdev/orbit-gateway:0.1.190-candidate-20260804T052051Z-106cb613f@sha256:97bd0cb4d208509bcf3ec30665e8902c4afc2996301384bf7ef0fb0a415dd6d3`; no GitHub/tag/final GHCR version tag
- Identities: feature=main=origin/main=106cb613fba5c39e8618ce8e053f19546489045d

## Feedback

- Events: `.orbit/feedback.jsonl`
