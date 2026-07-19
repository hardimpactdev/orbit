# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: none; approved in the current Codex task after a bounded DNS ownership audit
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-dns-doctor-ownership`
- Branch: `codex/dns-doctor-ownership`

## Goal

DNS record projection and Doctor repair have one public owner per fact: node
owns node-host and role-derived wildcard records, proxy owns router/private
service records, and tool owns only dnsmasq base configuration and runtime.
The running resolver mounts every owner-specific projection, and focused tests
prove that one drift produces one family issue and one owner-scoped restore.

## Scope

- Owned: `PRODUCT_DECISIONS.md`; DNS, node, proxy, tool, and Doctor contracts
  under `apps/docs/content/`; gateway DNS rendering/reconciliation, node/proxy/
  tool Doctor probes and restore routing under `apps/gateway/app/`; focused Pest
  coverage under `apps/gateway/tests/`.
- Constraints: preserve the nine existing state families; create no `dns` role
  or DNS state family; keep caller-local `dns:*` and public Cloudflare DNS out of
  the gateway projection; shared reload/restart is a side effect and never
  transfers issue ownership; do not run `composer test:e2e*`; preserve unrelated
  state.
- Out of scope: caller-local resolver implementation, public production DNS,
  unrelated Doctor-family ownership findings, and general update-runner repair.

## Proof

- Verification:
  - focused: passed - gateway DNS/Doctor/schema/API 143 tests and 974 assertions; CLI node contract 76 tests and 286 assertions; core NodeTld 13 tests and 14 assertions; post-review DNS/Doctor/NodesProbe slice 231 tests and 1202 assertions
  - broader: passed - exact-tip serialized `composer quality-check` passed every app/package lane: gateway Pest 5138 tests/30024 assertions, CLI Pest 2351/9746, docs Pest 174/11703, core Pest 142/552, SDK Pest 133/453, docs lint, all Mago and Rector checks, both Cargo suites, and `git diff --check`
  - runtime: passed - retained Incus topology `dev-7df17b` on `beast` proved source identity at executable tip `570226b4206820219254bb5ff669dae0fae9c056`, stable VPN/DNS services, read-only projection mounts, owner-isolated Node/Proxy/Tool drift detection and restore, and peer DNS resolution; the feature's later DNS delta changes only two downstream ownership-doc files, while integrated main changes only unrelated fleet-update and `.orbit/sessions/` archive surfaces; DNS runtime bytes remain identical; evidence `.orbit/evidence/dns-doctor-ownership-dev-7df17b.md`
- Blast radius: complete - evidence=repository-wide node TLD, WireGuard-address, activation-transition, DNS-reconciler, old Tool semantic-code, whole-file repair, legacy combined-builder, DNS-family, DNS-role, and ambiguous ownership-wording inventory plus focused NodesProbe execution; result=no residual duplicate owner, unguarded DNS-input mutation, retired production identifier, DNS family/role, or A7 wording ambiguity remains
- Review: passed - human-judgment=not-required; independent general reviewer found no actionable findings, confirmed the feature patch is byte-identical to the previously reviewed DNS patch, and confirmed the integrated current-main delta is unrelated to DNS ownership
- Reviewed feature tip: 57732ed3aedb73d78b537e2c0ff90eddfa5d7745
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 57732ed3aedb73d78b537e2c0ff90eddfa5d7745
- Accepted main tip: 01064868ce7123ecc8befdee8709acd6df22445e

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
concise reason` or `complete - evidence=repository-wide search, inventory, or
lintable check; result=concise summary` before acceptance; `gaps` returns to BUILD.
Proof files retained by the compact archive must be cited as one exact
inline-code path; prose, directories, padded code spans, and partial paths are
not proof citations.
