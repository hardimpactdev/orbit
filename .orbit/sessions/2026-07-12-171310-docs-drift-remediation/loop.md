# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `solo://proj/4/scratchpad/docs-audit-final--303` revision 75
- Worktree: /Users/nckrtl/orbit/.worktrees/docs-drift-remediation
- Branch: docs-drift-remediation

## Goal

Resolve every approved A1-A23, B1-B8, and C1-C6 docs-drift finding with product decisions, docs, tests, migrations, and implementation aligned.

## Scope

- Owned: `PRODUCT_DECISIONS.md`; `apps/docs/content/**`; docs lint configuration/tests; affected CLI, gateway, SDK, core, Agent, macOS, migration, and focused test surfaces named by scratchpad 303.
- Constraints: no supported legacy tier; provisioning/bootstrap is the sole permanent Orbit-managed SSH lane; every remaining non-provisioning SSH consumer uses exact `transitional-ssh`; workload execution uses Agent push; operation progress uses WebSocket/Reverb plus journal replay; profile is local-only; no E2E commands.
- Out of scope: break-glass operator SSH; manual E2E execution; the pre-existing `docs/superpowers/plans/2026-07-08-instance-runtime-mounts.md`; unrelated cleanup.

## Proof

- Verification:
  - focused: passed - docs lint completed with zero errors; docs Pest passed
    136 tests with 1,063 assertions; focused gateway, CLI, Core, SDK, Agent,
    macOS, TypeScript SDK, generator, SSH-inventory, migration, and contract
    checks passed; rollout migration suites passed 12 tests with 66 assertions;
    final no-SSH gateway host installer and ordering checks passed 134 gateway
    tests with 644 assertions, plus 31 focused event/installer tests with 145
    assertions; Agent trust propagation and atomic-failure safety passed 14 CLI
    tests with 103 assertions
    and 23 gateway tests with 203 assertions; focused Mago lint and `git diff
    --check` passed
  - broader: passed - exact clean tip
    `4136cac3f67f8fc5f6f3cec8b18bf18f7663b560` passed `composer
    quality-check` across all nine apps/packages; profile
    `.orbit/quality-gates/profiles/2026-07-12T14-46-46Z-4136cac3f67f`
  - runtime: passed - live topology identity at `2026-07-12T13:04:33Z`
    proved `platform11` is local to Beast at
    `/home/nckrtl/apps/platform11`, with a matching `development` Orbit
    instance, a separate `cloud` instance, and an app-level SQLite target at
    that local path; exact migration replay proves all historical rows select
    the matching Orbit instance, preserves unrelated same-prefix Cloud targets,
    and aborts before mutation on resolved-owner conflicts; live candidate
    `20260712T133147Z-80c87a249` completed migrations and converged the Swarm
    gateway; follow-up live attempts exposed the fixed-name container and
    gateway-host token gaps, with scheduler recovery succeeding after each;
    the final implementation removes that SSH consumer, verifies the relayed
    artifact in a pinned local Docker helper, and activates it before Swarm
    task recreation; candidate `20260712T142239Z-3fcb9d433` then updated the
    gateway and all seven workload targets but exposed missing Agent CA trust
    on the `agent` node during final Agent-push verification; the exact-tip
    repair installs the rendered gateway CA before restarting Agent; the
    focused executor proof exercises the exact install path, including a
    destination-staging failure that preserves both live trust files and makes
    no restart call; exact pushed candidate
    `20260712T145011Z-4136cac3f` then verified all four native artifact hashes,
    gateway digest `sha256:eec4639330bc1c428134e01a5a504576ac61f2f4d0e28bdeb6c975356055af3b`,
    and Reverb digest
    `sha256:73f9afcfb80b56bbd99a539ee7b0f8c2bbb385a58639e5d291877d98acddb849`;
    the first pass updated every target and proved CA-backed Agent push on the
    Linux fleet, then correctly failed final verification because a stale
    pre-existing Mini diagnostic process owned port 9477; bounded break-glass
    recovery removed that exact rogue process and restored the existing
    LaunchAgent; rerun operation
    `f6574739-2782-47ae-ab86-d9f380a93fca` completed with gateway, scheduler,
    seven workload CLIs, all Agent artifacts, and required role images
    verified; live ownership now has zero app-level database targets, 21
    app-instance targets, and `platform11` SQLite attached only to
    `platform11.development` while its Cloud instance remains separate; the
    node TLD constraint migration completed and the all-node node-family doctor
    reports zero TLD issues; scoped Beast app restore re-applied 19 canonical
    app-instance runtime configs and the follow-up app-family doctor is healthy;
    final fleet doctor reports 47 unrelated issues versus 49 in the
    transport-blocked pre-update snapshot, with no missing-CA or Mini transport
    regression
- Review: passed - independent exact-tip reviewer found no findings after atomic trust-file replacement; human-judgment=not-required
- Reviewed feature tip: 4136cac3f67f8fc5f6f3cec8b18bf18f7663b560
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 4136cac3f67f8fc5f6f3cec8b18bf18f7663b560
- Accepted main tip: 3fcb9d433b72114549fb93ce59a37afec79c69ec

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
