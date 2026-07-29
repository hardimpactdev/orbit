# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: not-required
- Worktree: /Users/nckrtl/orbit/.worktrees/codex-dependency-cold-wake-page
- Branch: codex/dependency-cold-wake-page

## Goal

After seven days without HTTP, lifecycle, or source activity, an app-dev
instance or workspace whose generated dependency directories are safely
reconstructable becomes cold: Orbit removes those directories, and its next
request immediately renders a minimal progress page while restoring only the
detected dependencies and starting the scope's actual process set before
reloading the ready application.

## Scope

- Owned: development runtime hibernation/cold-state policy, gateway activation
  operation and progress response, node-local dependency inspection/pruning and
  restoration, stock-Caddy wake routing, authoritative product docs, and
  focused gateway/runtime tests.
- Constraints: app-dev instances and workspaces only; one-hour process
  hibernation remains unchanged; seven-day eligibility uses the latest known
  HTTP, process-lifecycle, or source-tree activity; shared source paths use the
  newest activity across all owners; pruning requires a deterministic lockfile
  and contained non-symlink dependency target; Composer and package-manager
  caches remain; activation is serialized; the minimal progress response
  renders one aggregate bar from planned dependency steps and actual configured
  processes without exposing individual visual rows.
- Out of scope: production and node-owned process lifecycles, custom Caddy
  modules/images, deleting lockfiles/build artifacts/caches, dependency
  upgrades, arbitrary setup-step replay, and exposing commands, paths,
  environment values, or raw logs on the public progress page.

## Proof

- Verification:
  - focused: passed - gateway 213 passed/1285 assertions; CLI 25 passed/134
    assertions; stock `caddy:2-alpine` adapted the overlapping cold/asleep
    matcher route in the intended order; evidence=`.orbit/evidence/local-proof.txt`
  - broader: passed - `composer quality-check` lanes passed; one CLI shard
    process timeout was isolated and its exact file set passed on the narrow
    rerun;
    evidence=`.orbit/evidence/local-proof.txt`
  - browser: committed Blade view passed desktop and iPhone 14 accessibility,
    console-error, visual inspection, and measured cross-refresh progress
    interpolation;
    evidence=`.orbit/evidence/local-proof.txt`
  - runtime: passed - the committed Blade response rendered through the gateway
    app on desktop and mobile, retained its failed retry path, and interpolated
    a same-tab 25% to 50% update; the merged current-version candidate then
    passed live fleet update and real-browser cold-wake checks on NMBP
  - Retained topology proof: passed - candidate
    `20260729T054522Z-0d381cd66` from merged/pushed commit
    `0d381cd6637cbdf4aaf0ca74c89cb2a3567220d6` completed live `update:all`
    as activity `298990`; gateway digest
    `sha256:fde30fedab6e115e9af52815ab7bade2beb8b3995a6d9dc6d93b74bcca240528`;
    post-update doctor returned 136 existing issues versus 138 before after
    reconverging only release-caused proxy render drift on NMBP and beast;
    browser-triggered cold wakes returned `horizon-demo.nmbp` in 88ms and
    `nckrtl.nmbp` in 2020ms with fresh started events; evidence=
    `.orbit/evidence/local-proof.txt`
- Blast radius: complete - evidence=`rg -n "RuntimeDependencies|runtime-activation|runtime-cold|runtime-warm|dependencyFenceKey" apps packages` plus the closed internal-command role allow-list, hidden command inventory, proxy-render, shared-source concurrency, cold-sweep, and docs-reference checks; result=app-dev app-instance and workspace behavior is covered while production and node-owned runtimes remain excluded
- Review: passed - human-judgment=not-required; independent exact-tip review
  found no actionable findings after the visually inert progressbar semantics
  correction
- Reviewed feature tip: 0d381cd6637cbdf4aaf0ca74c89cb2a3567220d6
- Acceptance venue: browser
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 0d381cd6637cbdf4aaf0ca74c89cb2a3567220d6
- Accepted main tip: 9cb9d81870ee9152626b543cc46d2e4e81fdfa1b

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
