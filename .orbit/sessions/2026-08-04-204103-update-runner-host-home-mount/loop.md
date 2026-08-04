# Orbit Feature Loop

- Scratchpad: none
- Worktree: /Users/nckrtl/orbit/.worktrees/update-runner-host-home-mount
- Branch: update-runner-host-home-mount

## Goal

UpdateRunnerLauncher one-shot containers with ORBIT_HOST_PATH_PREFIX also bind-mount host /home at /mnt/orbit-host/home:ro matching GatewaySwarmStackRenderer so entrypoint config-root ownership resolution succeeds and durable update:all runners progress past entrypoint instead of exiting immediately with only tree+plan events.

## Scope

- Owned:
  - apps/gateway/app/Services/Operations/UpdateRunnerLauncher.php
  - apps/gateway/tests/Feature/Services/Operations/UpdateRunnerLauncherTest.php
  - apps/docs/content/tech-stack.md
- Constraints: no VERSION bump, no GitHub stable release/tag, preserve unrelated state
- Out of scope: FrankenPHP terminating redesign, bulk doctor restore, GH stable

## Proof

- Verification:
  - focused: passed - UpdateRunnerLauncher Pest 7 passed on tip 9aa5d390c977f65dd4e015798f44699add6233c5
  - broader: passed - composer quality-check exit 0 dirty=false tip 9aa5d390c977f65dd4e015798f44699add6233c5 evidence `.orbit/quality-gates/quality-check-2026-08-04T183759Z-1b6183fc1680.json`
  - runtime: passed - live without /home entrypoint failed at /mnt/orbit-host/home/orbit evidence 36; live with /home mount op df1eaebf succeeded including gateway.leaf evidence 37; product launcher mount locked by Pest
- Blast radius: complete - evidence=repository-wide ORBIT_HOST_PATH_PREFIX and update-runner docker mount search; result=only UpdateRunnerLauncher lacked host /home mount while Swarm gateway already mounted it
- Review: passed - human-judgment=not-required
- Reviewed feature tip: 9aa5d390c977f65dd4e015798f44699add6233c5
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 9aa5d390c977f65dd4e015798f44699add6233c5
- Accepted main tip: 9c28655780bad7217981afc3cf3210f13b04f2a0

## Status

- State: land
- Blocker: none

## Feedback

- Events: .orbit/feedback.jsonl
