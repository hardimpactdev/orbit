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

The reviewer confirmed the four branch-local documentation waivers and found no new extension plug-in point. Incus reproduction remains outstanding. A second review checks the fix and rebased feature before the PR opens.
