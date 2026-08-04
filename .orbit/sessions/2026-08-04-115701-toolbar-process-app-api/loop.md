# Orbit Feature Loop

- Scratchpad: none
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-toolbar-process-app-api`
- Branch: `codex/toolbar-process-app-api`

## Goal

Expose Laravel Toolbar-ready process list/start/stop/restart on the existing
gateway process routes with an `app` hostname selector (proxy_routes.domain
precedence), concrete normalized process `status`, durable Started events on
ordinary start paths, browser CORS Origin admission for those routes only, CLI
`--app` support, OpenAPI + TypeScript SDK types, and release-ready packaging for
`@hardimpactdev/orbit-sdk-typescript` (generated split
`hardimpactdev/orbit-sdk-typescript`, root VERSION authority).

## Scope

- Owned: process app-selector API/CLI/SDK/docs/release packaging surfaces and
  finalization quality-check expected subgate alignment with
  `bin/quality-check.sh` CHECK_LABELS.
- Constraints: WireGuardIdentity + grants preserved; no push/publish/deploy
  from agent without authorization.
- Out of scope: RC build, tag, publish, npm publish, fleet mutation in this
  pass.

## Authorization

- Feature tip under review:
  `226b03d8c2d16e7f472b632be78347ec33a295df`.
- Prior product acceptance on `4ac2fc1fa016e970d22b8c7db165abaafdbff4ef` was
  superseded by finalization-tooling commits; re-review/re-acceptance bind the
  new tip only.
- Retained-Incus product proof completed earlier on disposable topology
  `dev-736935` and reaped; reviewer marks runtime reproof not-required for the
  finalization-only delta.
- External split repo `hardimpactdev/orbit-sdk-typescript` exists; npm first
  publish remains a separate release-path concern.

## Proof

- Verification:
  - focused: passed - finalization quality-check subgate alignment with quality-check.sh CHECK_LABELS (producer, hook expected list, and fixture exact equality; sdk_typescript_build/typecheck presence) plus forged quality-check evidence suite on tip `226b03d8c2d16e7f472b632be78347ec33a295df`
  - broader: passed - `composer quality-check` exit 0 on clean HEAD `226b03d8c2d16e7f472b632be78347ec33a295df` dirty=false via `.orbit/quality-gates/quality-check-2026-08-04T095313Z-3c4bb1426243.json` (45 subgates all 0 including TypeScript lanes); gateway pest log `.orbit/quality-gates/profiles/2026-08-04T09-52-11Z-226b03d8c2d1/gateway_pest.log`
  - runtime: passed - retained-incus product proof for app-selector process list/start/stop/restart on topology id=dev-736935 kind=operator_gateway_app-dev via `.orbit/evidence/retained-incus-process-app-selector.txt` (topology reaped); RUNTIME_REPROOF not-required for post-product finalization tooling/test delta only
- Blast radius: complete - evidence=independent general review repository-wide identifier inventory of quality-check subgate consumers (CHECK_LABELS producer, QUALITY_CHECK_EXPECTED_SUBGATES, finalization fixture) and process AppOwning/event-shape consumers from the product surface; result=no unexamined consumer; prior P2 closed by three-way CHECK_LABELS equality; zero remaining findings
- Review: passed - independent general reviewer - human-judgment=not-required
- Reviewed feature tip: 226b03d8c2d16e7f472b632be78347ec33a295df
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 226b03d8c2d16e7f472b632be78347ec33a295df
- Accepted main tip: a3ee576ec304985bb4f6f6ae94e167f96b277c42

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
