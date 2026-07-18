# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: codex://current-task
- Worktree: /Users/nckrtl/orbit/.worktrees/codex-fix-caddy-certificate-reload
- Branch: codex/fix-caddy-certificate-reload

## Goal

When Orbit replaces route certificate files, Caddy force-reprovisions its unchanged configuration so live TLS handshakes immediately serve the replacement leaf.

## Scope

- Owned: `apps/cli/app/Services/Caddy/LocalCaddyConfigAction.php`, its focused Pest coverage, and proxy TLS convergence documentation.
- Constraints: preserve the fixed internal execution surface; prove the regression red before implementation; verify real served certificates on the live gateway and NMBP nodes.
- Out of scope: public ACME certificates, unrelated proxy route/runtime drift, and changes to the 397-day leaf policy.

## Proof

- Verification:
  - focused: passed - regression failed without `--force`; final `InternalCaddyConfigCommandTest` passed 15 tests / 97 assertions; docs lint, secret scan, and diff check passed
  - broader: passed - `ORBIT_QUALITY_CHECK_CPU_BUDGET=2 composer quality-check` passed at exact candidate 8fba5fd81e68759b6aaddfa6ffa27839827c4920 with every subgate exiting 0; receipt `.orbit/quality-gates/quality-check-2026-07-18T085230Z-d56541405321.json`
  - runtime: passed - retained Incus topology dev-f911ed proved an unchanged Caddy configuration kept serving leaf A after leaf B replaced it on disk, then the exact candidate's source-mounted forced reload made an SNI TLS 1.3 handshake serve leaf B with Orbit-root verification OK; live rollout then verified all 47 registered private Orbit-root proxy routes by SNI, hostname, chain, and Apple-compatible validity window with zero failures; evidence `.orbit/evidence/caddy-forced-reload-retained-proof.txt` and `.orbit/evidence/caddy-forced-reload-live-proof.txt`
- Blast radius: complete - evidence=bounded repository inventory of `ProxyRouteFixer`, `RemoteCaddyConfig`, `internal:caddy-config`, and the separate tool-reconfiguration surface; result=all route-certificate replacement flows converge through the changed internal reload action with no unaddressed consumer
- Review: passed - no actionable findings; human-judgment=not-required
- Reviewed feature tip: 8fba5fd81e68759b6aaddfa6ffa27839827c4920
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 8fba5fd81e68759b6aaddfa6ffa27839827c4920
- Accepted main tip: 7ca2d6d195d01e35ab7096f334cbe4ce2fc46b92

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
