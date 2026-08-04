# Orbit Feature Loop

- Scratchpad: none
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-sdk-browser-api-base`
- Branch: `codex/sdk-browser-api-base`

## Goal

`createOrbitGatewayClient({ baseUrl: "https://gateway.orbit" })` resolves OpenAPI requests to the gateway `/api` base so `POST /processes/start` hits `https://gateway.orbit/api/processes/start`, matching SSE and OpenAPI servers, without doubling when base already ends with `/api`.

## Scope

- Owned: `packages/sdk-typescript` client base URL resolution, tests, README, package version 0.2.1
- Constraints: no Toolbar fork, no gateway route changes, no npm publish/merge/push from this land phase
- Out of scope: Gateway controllers, browser TLS SAN, SSE CORS, npm publication

## Proof

- Verification:
  - focused: passed - packages/sdk-typescript `npm test` 17/17 on exact tip `21800143a56f3aa754dacf060510643422a354dd` (client baseUrl resolution + process-stream); build and pack dry-run for 0.2.1
  - broader: passed - `composer quality-check` exit 0 dirty=false tip `21800143a56f3aa754dacf060510643422a354dd` all subgates 0 via `.orbit/quality-gates/quality-check-2026-08-04T162057Z-ce3e53dd235c.json`
  - runtime: passed - independent review PASS; package-only client baseUrl contract exercised by focused SDK tests and monorepo quality-check on exact tip; no retained topology id claimed for this SDK packaging delta
- Blast radius: complete - evidence=repo-wide createOrbitGatewayClient consumer inventory plus package tests; result=macOS existing /api base remains single /api, gateway-root resolves to /api, no other consumers
- Review: passed - human-judgment=not-required - VERDICT=PASS BLAST_RADIUS=complete independent general review of 21800143a vs base 66b051794
- Reviewed feature tip: 21800143a56f3aa754dacf060510643422a354dd
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 21800143a56f3aa754dacf060510643422a354dd
- Accepted main tip: 66b05179409a1c356b817add69bb49c457eb4b29

## Status

- State: land
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
