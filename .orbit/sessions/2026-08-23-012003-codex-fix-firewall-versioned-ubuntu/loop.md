# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Session: feat-codex-fix-firewall-versioned-ubuntu
- Worktree: /Users/nckrtl/orbit/.worktrees/codex-fix-firewall-versioned-ubuntu
- Branch: codex-fix-firewall-versioned-ubuntu

## Goal

Firewall list, create, and remove operations accept active managed Ubuntu nodes
whose canonical platform includes a version suffix, while preserving the
existing role, status, visibility, and authorization boundaries.

## Scope

- Owned: `apps/gateway/app/Services/Firewall/FirewallRuleQuery.php`,
  `apps/gateway/app/Services/Firewall/FirewallRuleIntent.php`, shared
  `FirewallTargetPlatform.php`, and their focused gateway unit tests.
- Constraints: TDD with captured RED and GREEN; preserve active-role and access
  gates; match the existing exact `ubuntu` or `ubuntu_` prefix convention; no
  human-only E2E commands.
- Out of scope: CLI rendering or signatures, unsupported-platform policy,
  unrelated platform normalization, public GitHub release publication, and the
  live Beast firewall mutation until the accepted code is deployed.

## Proof

- Verification:
  - focused: passed - evidence=captured RED then focused FirewallRuleQuery and FirewallRuleIntent Pest plus Mago format and analysis; result=32 tests passed with 96 assertions
  - broader: passed - evidence=`.orbit/quality-gates/quality-check-2026-08-22T230647Z-6fe68a0b51e8.json`; result=`composer quality-check` passed at 0499352501b627146956cffc528a093a51876f44
  - runtime: passed - candidate=0499352501b627146956cffc528a093a51876f44; venue=retained-incus; environment=dev-fixture; target=Beast topology dev-ed0062 kind operator_gateway_app-dev node app-dev-1 platform ubuntu_24-04; expected=create converge scoped list healthy doctor remove empty list healthy doctor; observed=backend_enacted true action converged count one doctor healthy backend_removed true count zero final doctor healthy; result=passed; evidence=`.orbit/evidence/firewall-versioned-ubuntu-retained-incus.md`
- Blast radius: complete - evidence=fresh repository-wide inventory of firewall entry points, all node-resolution sites, SQL and PHP Ubuntu predicates, CLI, SDK, Doctor, roles, and metrics; result=every documented firewall surface reaches the corrected predicate and one SQL definition remains
- Review: passed - human-judgment=not-required; reviewer=Claude general; result=no blocking findings at exact rebased candidate
- Reviewed feature tip: 0499352501b627146956cffc528a093a51876f44
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 0499352501b627146956cffc528a093a51876f44
- Accepted main tip: bd4b2285fbbf59623b38514c43582b863707b842

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
