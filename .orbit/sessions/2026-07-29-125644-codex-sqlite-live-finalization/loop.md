# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad:
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-sqlite-live-finalization`
- Branch: `codex/sqlite-live-finalization`

## Goal

Production instance env writes succeed as the owning runtime user over the
selected node transport, and accepted FrankenPHP candidates can be promoted
while registry credentials remain isolated from the user's Docker config.

## Scope

- Owned: instance env apply payload/action, runtime promotion helper, focused
  tests, and exact live Hauzer proof.
- Constraints: preserve unrelated work, no Hauzer code changes, no GitHub
  release, and never expose env secrets or registry tokens.
- Out of scope: broader env-file transport refactors and the separate Beast
  Orbit Agent artifact confirmation race.

## Proof

- Verification:
  - focused: passed - 28 tests, 208 assertions across env application and
    runtime promotion
  - broader: passed - `composer quality-check` at committed tip `a03e681b0`;
    transient SIGTERM classified as tooling interruption, then 2,388 CLI tests
    passed serially before the final gate passed
  - runtime: passed - retained topology `dev-d9368f`
    (`operator_gateway_app-dev_app-prod`) proved production `instance:env
    --apply` over Agent Push as the runtime user with suppressed audit output;
    evidence=`.orbit/evidence/runtime-proof.txt`
- Blast radius: complete - evidence=`rg -n "LocalEnvFileAction|RemoteEnvFile|runtime_user|ghcr_docker_config|docker-buildx" apps bin`; result=all callers preserve the optional runtime-user payload and the Buildx link is scoped to the temporary Docker config
- Review: passed - human-judgment=not-required
- Reviewed feature tip: a03e681b046b09f17b1388a759e4c2e224a86251
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: a03e681b046b09f17b1388a759e4c2e224a86251
- Accepted main tip: bf1a411ef7efb9566a201968e650426a85027946

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
a reason` or `complete - evidence=repository-wide search, inventory, or
lintable check; result=summary` before acceptance; `gaps` returns to BUILD.
Proof files retained by the compact archive must be cited as one exact
inline-code path, for example `.orbit/evidence/runtime-proof.txt`; prose,
directories, padded code spans, and partial paths are not proof citations.
