# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: none
- Worktree: /Users/nckrtl/.codex/worktrees/fix-websocket-role-redis-resolution
- Branch: codex/fix-websocket-role-redis-resolution

## Goal

`node role:add services1 websocket --redis-node=database1` resolves the documented Redis node name to `redis_node_id` and deploys the websocket role.

## Scope

- Owned: gateway node-role settings resolution, websocket runtime networking, and focused API/runtime regressions
- Constraints: preserve the existing API envelope and require an active database node with a managed Redis process
- Out of scope: unrelated fleet doctor findings, agent update confirmation behavior, and other node roles

## Proof

- Verification:
  - focused: passed - `bin/orbit-gateway-pest --compact tests/Feature/Http/Api/NodeRoleAddControllerTest.php` passed 16 tests and 95 assertions; scoped Mago format and Rector dry-run passed
  - broader: passed - exact feature tip `5dc86c3f30f0380a4a7578a3d9aa9972fd0e7751` passed all nine `composer quality-check` units; receipt=`.orbit/quality-gates/quality-check-2026-07-17T084146Z-baa84c696d70.json`
  - runtime: passed - topology=dev-91cd83; kind=operator_gateway_app-dev; host=beast; roles=operator,gateway,dev; result=managed Redis PONG, name resolved to redis_node_id, Reverb stable across the Redis timeout window on host networking, private TLS listener reachable, duplicate assignment rejected; evidence=`.orbit/evidence/runtime-proof.txt`
- Blast radius: complete - evidence=33-reference Redis-setting inventory across CLI, gateway, runtime, doctor, SDK, and docs; result=external name and stored ID vocabulary remain aligned
- Review: passed - human-judgment=not-required; no findings; host networking keeps Reverb bound to WireGuard without Docker socket or new writable host mounts
- Reviewed feature tip: 5dc86c3f30f0380a4a7578a3d9aa9972fd0e7751
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 5dc86c3f30f0380a4a7578a3d9aa9972fd0e7751
- Accepted main tip: f1d93735a09e8a17867dc071c1cbbef6fcf5260e

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`

Allowed states are `frame`, `build`, `prove`, `accept`, `accepted`, `land`, and
`blocked`. Review must be `passed`, `fix`, or `escalate`. Acceptance must be
`pending`, `accepted`, `not-applicable`, or `changes-requested`. A terminal
review PASS must record `human-judgment=required|not-required` and the exact
committed HEAD in `Reviewed feature tip`. Blast radius must be `not-required -
reason` or `complete - evidence=repository-wide search, inventory, or
lintable check; result=summary` before acceptance; `gaps` returns to BUILD.
Proof files retained by the compact archive must be cited as one exact
inline-code path, for example `.orbit/evidence/runtime-proof.txt`; prose,
directories, padded code spans, and partial paths are not proof citations.
