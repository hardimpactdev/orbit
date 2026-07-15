# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `solo://proj/4/scratchpad/docs-audit-final--312`
- Worktree: /Users/nckrtl/orbit/.worktrees/fix-docs-audit-drift
- Branch: fix-docs-audit-drift

## Goal

Align Orbit's current product and command documentation with all 12 approved
docs-drift findings, retire the stale `node.ssh_unreachable` vocabulary, and
prove the corrected contracts through focused tests and repository quality
gates.

## Scope

- Owned: `apps/docs/content/**` transport, Node, Workspace, Doctor, and testing
  contracts; `apps/docs/app/Librarian/Rules/SharedFailureVocabularyRule.php`;
  focused docs-app tests; `apps/gateway/app/Services/Workspaces/WorkspacesProbe.php`;
  `apps/gateway/app/Services/Nodes/NodesProbe.php`; focused gateway probe tests;
  generated docs artifacts when their native builders require updates.
- Constraints: preserve the 2026-07-15 client-owned bootstrap and Agent-push
  decisions; develop the PHP linter delta test-first; use only automated proof;
  do not run any `composer test:e2e*` lane.
- Out of scope: other gateway or CLI runtime behavior, unrelated baseline FleetUpdate
  and NodeBootstrap failures, dependency changes, and adjacent documentation
  cleanup outside findings A1-A8, B1-B3, and C1.

## Proof

- Verification:
  - focused: passed - docs rules, CLI node-new/write, gateway workspace-show, workspace
    probes, node probes, and pending-role JSON contracts passed 326 tests /
    1,405 assertions; docs lint
    passed with 71 pre-existing warnings; PHP syntax and CLI/docs/gateway Mago
    formatting passed. Preparation baseline on untouched `d0d84b1` recorded
    4,749 passed, 10 failed, and 2 errored in unrelated gateway tests
  - broader: passed - `composer quality-check` passed all apps and packages at
    `22695f3e640e54ec87779a1f0b66449a66346db2`; profile
    `.orbit/quality-gates/profiles/2026-07-15T19-49-57Z-22695f3e640e`.
    A prior run's isolated `core_pest=143` interruption was classified as
    infrastructure/tooling after the lane passed 127 tests / 534 assertions in
    1.16s; the full exact-candidate rerun then passed. Final-check confirmed
    exact docs-lint and quality-check identity with only warning-only local
    timing regressions
  - runtime: passed - retained Incus topology `dev-4f19cd` (`operator_gateway`) on
    `beast`, driven from Solo terminal `1079`. Inspected
    `orbit-e2e-dev-4f19cd-operator` and `orbit-e2e-dev-4f19cd-gateway` at
    `/home/orbit/orbit-run`; both launchers resolved to the mounted
    `apps/cli/orbit`. Six production-file SHA-256 values matched the reviewed
    local candidate. Inside the gateway VM, the workspace-show, node-store,
    role-resolver, node-probe, and workspace-probe files passed 155 tests / 446
    assertions in 16.82s. Inside the operator VM, the node-new/write/bootstrap/
    stream files passed 90 tests / 337 assertions in 4.01s. The initial shared
    `base` acquisition was unavailable because its source snapshots were
    missing; isolated `fix-docs-audit-drift` snapshots were built from exact
    Git ref `22695f3e640e54ec87779a1f0b66449a66346db2`. The unrelated downstream
    app-role portion of that artifact build failed after the required operator
    and gateway snapshots completed; retained acquisition and both required
    role proofs passed from those isolated snapshots
- Review: passed - independent exact-tip reviewer found no actionable findings after the requested corrections - human-judgment=not-required
- Reviewed feature tip: 22695f3e640e54ec87779a1f0b66449a66346db2
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 22695f3e640e54ec87779a1f0b66449a66346db2
- Accepted main tip: d0d84b1649777a338982fa9ee241d49722845c33

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`

Allowed states are `frame`, `build`, `prove`, `accept`, `accepted`, `land`, and
`blocked`. Review must be `passed`, `fix`, or `escalate`. Acceptance must be
`pending`, `accepted`, `not-applicable`, or `changes-requested`. A terminal
review PASS must record `human-judgment=required|not-required` and the exact
committed HEAD in `Reviewed feature tip`.
