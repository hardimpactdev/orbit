---
title: "PowerSync path proxy status"
description: "Branch handoff and verification for the public app Route path proxy."
---

# PowerSync path proxy status

The branch adds one reserved path proxy per production Laravel Instance and public app Route. The default endpoint is `https://<app-domain>/powersync`. Caddy strips the prefix and streams requests to a Node-owned Docker Process on its WireGuard TCP port.

Local verification uses database fixtures, mocked SSH, and real local Caddy. No live Nodes, production Instances, or Routes are changed. Incus reproduction remains for the independent Orbit reviewer before any future merge. This PR must not be merged by this agent.

## Verification

Affected Gateway tests pass: 136 tests and 1,035 assertions. Coverage includes API and MCP lifecycle, both-Node authorization, Laravel collisions, Caddy build retries, disabled withdrawal intent, Route and Process guards, Doctor, public Reverb regressions, and real local Caddy prefix stripping and response streaming. The Python route-inspection class passes four tests.

CLI contract and terminal consent checks pass: 72 tests. SDK request and response checks pass: 17 tests and 30 assertions. Real Linux terminal recordings cover explicit Yes and default No at 80 columns. Sanitized recordings are outside Git at `/tmp/powersync-path-proxy-terminal`. CLI PTY tests also cover explicit No, Ctrl-C, EOF, and terminal restoration.

Scoped PHPStan, Pint, and Rector check the changed PHP sources. Generated web types pass `vp check --no-fmt src/api/schema.d.ts`; formatting excludes that generated file by repository configuration. Existing generated schema sections emit 15 type lint warnings. Documentation build, lint, navigation, Mintlify validation, and broken-link checks pass.

## Independent review

The first independent review found that a pool update could move an Instance away from a proxied source Route or reassign it into a proxied destination. The guard now checks every affected Route before workload preparation and target changes. Four regression cases cover both directions with enabled publication and incomplete withdrawal. All four fail without the fix and pass with it.

The reviewer confirmed the four branch-local documentation waivers and found no new extension plug-in point. Incus reproduction remains outstanding. The second independent review approved PR preparation at `59408a4bae3fbc8fe7f0805a1540209817b3f4d6`, against base `120e4eda2da02dab929ff854a830994986abad8a`, with no new blocking defects.

An extra standalone PHPStan run on `bin/docs-nav` reports two existing CLI-entry-point type findings. The same check on the pinned base script reports the same two findings. The changed application sources pass their project PHPStan checks.

## CI contract fixtures

The first CI run found three exhaustive Gateway expectations that omitted the new path proxy contracts. The expectations now include its Doctor codes, its Route-owned model, and its three endpoint scopes. The Docs merge fixture now includes this required status page and refreshed context snapshots. The original failing cases were reproduced locally before the fixes.

The affected Gateway group passes 53 tests and 2,438 assertions. All 29 generated-merge checks pass. Scoped Pint and Rector pass. An extra PHPStan run on the changed tests reports ten existing findings in the node scope test, which is outside the project's configured analysis paths. The unchanged test at `b151a17faf5cb5ee84a26c97cbbda60ea3da89fd` reports the same ten findings.

Shard 1 also found that the new table migration sorted before the squashed schema baseline. Its filename now places it after all existing migrations. The unchanged schema baseline test reproduced the ordering failure before this fix. The schema baseline and path proxy group now passes 48 tests and 1,665 assertions.

The next CI run found that the SDK's exhaustive operation inventory omitted the three path proxy requests. The inventory now names them explicitly, and the package README reports 234 operations. The original guidance failure was reproduced before the correction. The guidance and request groups now pass 24 tests and 229 assertions. Changed SDK sources pass scoped PHPStan. An extra check of the guidance test, outside the project's configured analysis paths, reports the same nine findings as its unchanged version at `5c1d5e49187765ed31fcbf26f2e1b6963b8c41ae`.
