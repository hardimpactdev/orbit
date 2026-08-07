# Orbit Feature Loop

- Scratchpad: none (release loop)
- Worktree: /Users/nckrtl/orbit/.worktrees/release-0.1.191
- Branch: release/0.1.191

## Goal

Stamp root VERSION 0.1.191 and take the resulting release candidate through live topology acceptance (`update:all` across the fleet) with no GitHub publication.

## Scope

- Owned: root VERSION
- Constraints: no GitHub tag, release, assets, or final GHCR version tag move; candidate artifacts built from pushed origin/main; live fleet acceptance compared against the pre-release doctor baseline
- Out of scope: GitHub publication of v0.1.191, split package repos, npm publication

## Authorization

- User authorized version 0.1.191 and `update:all` verbatim: "2 yes 0.1.191 is fine and do the update:all."
- User restricted publication verbatim: "That's not the point to github just yet Only life topology"

## Proof

- Verification:
  - focused: passed - release and update behavior at tip cc5719d55a03b248993cd8ebe564a36b3dfc256b: `bin/orbit-gateway-pest tests/Feature/Release tests/Feature/Services/Operations/WorkloadNodeUpdaterTest.php` 59 passed, and `bin/orbit-version` reports 0.1.191
  - broader: passed - artifact `.orbit/quality-gates/quality-check-2026-08-07T123744Z-f5521bca2108.json` on exact commit cc5719d55a03b248993cd8ebe564a36b3dfc256b, exit_code 0, every subgate 0, dirty false
  - runtime: passed - candidate=911458734b7587bb4470a037311242d7005a2e2d; venue=retained-incus; environment=live; command=`ORBIT_RELEASE_MANIFEST_URL=https://s3.hardimpact.dev/orbit/channels/live-test/orbit-release-manifest.json ./apps/cli/orbit update:all --stream-json`; expected=the 0.1.191 candidate is accepted on the live fleet with gateway, scheduler, and every workload node updated and no new fleet drift; observed=update:all exit 0 with status succeeded from the topology-candidate manifest, gateway and scheduler verified on the digest-pinned candidate image, gateway:status moved 0.1.190 to 0.1.191, all nine workload nodes updated and verified, node:list still lists nine active nodes, and fleet doctor moved from 42 to 31 issues with zero new or increased entries and eleven resolved; result=passed; evidence=`.orbit/evidence/release-0.1.191/PROOF-MANIFEST.md`
- Blast radius: not-required - the diff is the single root VERSION file; no contract, schema, vocabulary, or ownership boundary changes
- Review: passed - human-judgment=required; the stamped content is a single root VERSION line already carried on main at 911458734, and the release behavior it gates was proven by live fleet acceptance recorded in the runtime receipt
- Reviewed feature tip: 911458734b7587bb4470a037311242d7005a2e2d
- Acceptance venue: automated
- Acceptance: accepted - user @ solo://projects/orbit/processes/1488#session-bfe7499f07396ea7
- Accepted feature tip: 911458734b7587bb4470a037311242d7005a2e2d
- Accepted main tip: 911458734b7587bb4470a037311242d7005a2e2d

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
