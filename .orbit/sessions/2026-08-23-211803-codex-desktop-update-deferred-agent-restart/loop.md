# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Session: feat-codex-desktop-update-deferred-agent-restart
- Worktree: /home/nckrtl/orbit/.worktrees/codex-desktop-update-deferred-agent-restart
- Branch: codex/desktop-update-deferred-agent-restart

## Goal

When a fleet update includes a Desktop handoff, the CLI installs the CLI and
Agent artifacts without requiring or restarting a standalone Agent service, so
Orbit Desktop can consume the handoff and restart its owned Agent atomically.

## Scope

- Owned: `apps/cli` fleet-update install environment/action and focused Pest coverage.
- Constraints: preserve standalone Linux systemd, legacy launchd, and unmanaged Agent restart behavior when no Desktop handoff is present; use the approved Desktop handoff contract; never run `composer test:e2e*`.
- Out of scope: Tauri/Desktop code, gateway update planning, release packaging, and unrelated Agent lifecycle behavior.

## Proof

- Verification:
  - focused: passed - `InternalFleetUpdateInstallCliCommandTest` 25 passed; `composer docs-lint` passed; install-script PHP hashes match topology-proven bytes
  - broader: passed - `composer quality-check`; artifact `.orbit/quality-gates/quality-check-2026-08-23T191340Z-6d1bda63af6a.json`; candidate `9384291231ad867577a7ff9dddb5a7621544b612`; dirty=false; exit=0
  - runtime: passed - candidate=9384291231ad867577a7ff9dddb5a7621544b612; venue=retained-incus; environment=dev-fixture; target=dev-61956a/orbit-e2e-dev-61956a-operator via Mini proof-1; expected=Desktop handoff installs verified CLI and Agent bytes without requiring a standalone service then writes an owner-only restart-ready handoff while an Agent-only update retains the existing service restart path; observed=install-script PHP hashes unchanged from topology-proven c8235c577ae00551257d777e66292b0e8ec768d5, apps/cli/app patch-id c22f610a20df1ae54e12e4c546cb98d661931526, Desktop handoff emitted defer_agent_restart_to_desktop with zero systemd or launchd calls and mode 0600 handoff, standalone update called systemctl status and restart; result=passed; evidence=`.orbit/evidence/retained-incus-proof.md`
- Retained topology proof: passed - topology id=`dev-61956a`; kind=`operator_gateway_app-dev`; host=`beast`; provider=`incus`; inspected instance=`orbit-e2e-dev-61956a-operator`; source checkout=`/home/orbit/orbit-run`; launcher=`/home/orbit/orbit-run/apps/cli/orbit`; install-script hashes match the topology-proven bytes after rebase onto 848dc136a86cd0a9dd6fe3a8b8b10cccab982a15; Desktop handoff deferred Agent restart with no systemd/launchd calls and wrote the verified owner-only handoff; standalone Agent update retained the systemd restart path; evidence=`.orbit/evidence/retained-incus-proof.md`
- Blast radius: complete - evidence=whole-feature and production-only patch-id equality across the rebase, repository-wide ownership-boundary inventory, disjoint base-delta inventory, and green generated command-catalog check; result=no affected surface remains unresolved
- Review: passed - independent exact-tip review confirmed patch and file-hash equivalence after the clean rebase, no base interaction risk, and valid SHA-bound receipts; human-judgment=not-required
- Reviewed feature tip: 9384291231ad867577a7ff9dddb5a7621544b612
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 9384291231ad867577a7ff9dddb5a7621544b612
- Accepted main tip: 848dc136a86cd0a9dd6fe3a8b8b10cccab982a15

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
