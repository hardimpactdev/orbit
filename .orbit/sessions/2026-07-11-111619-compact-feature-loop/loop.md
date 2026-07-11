# Orbit Feature Loop

- Scratchpad: solo://proj/4/scratchpad/zero-touch-self-comp--295
- Worktree: /Users/nckrtl/orbit/.worktrees/compact-feature-loop
- Branch: compact-feature-loop

## Goal

Replace the ceremonial feature workflow with a zero-touch, self-compounding loop that preserves quality, exact acceptance, and historical readability while minimizing user steering.

## Scope

- Owned: loop contract, finalization, acceptance, feedback, archive/index, active harness docs, agent prompts, skills, reviewer contract, and executable regression coverage.
- Constraints: preserve legacy/full archive readability and filesystem protections; never run or delegate composer test:e2e commands; preserve unrelated user state; every accepted surface must equal committed HEAD.
- Out of scope: generic semantic graders, metrics dashboards, routine analyzers/observers, and a fabricated live CLI acceptance trial for this non-observable tooling diff.

## Proof

- Verification:
  - focused: passed - active loop contract suite 183 tests / 693 assertions; FeatureAcceptance 26 tests / 98 assertions; hook harness passed; global `-h` and `--help` are state-free; venue, runtime-proof, reviewer-decision, actor-parity, invalidation, finalization, and feedback-promotion protections passed
  - broader: passed - `.orbit/quality-gates/quality-check-2026-07-11T090914Z-aa1da936c214.json`; clean `9f5b0a8ed4fe50585e9d5f0708a36b254c5c01c4`; 43/43 subgates passed in 49 seconds
  - runtime: passed - retained topology id=dev-a7a6bd kind=operator_gateway_app-dev provider=incus host=beast roles=operator,gateway,dev; exact-tip repeat sync 5.32s; all four acceptance/feedback `-h` and `--help` probes passed as the `orbit` user from `/home/orbit/orbit-run`; all 79 changed non-session files share digest `45e7e86d0aaccdba1de7212d35d80b10dda927c56af2046b95ae7f8454fe5c1b` across local candidate, Beast scoped source, and all three runtime checkouts; each role has exactly one overlay and the expected source device; lifecycle + mutation flocks are available with legacy lock and generation absent; app-dev `orbit-caddy` is running with root:root 0755 `/run/php`; manifest identity verified; evidence=Solo terminal `compact-loop-acceptance-dev-a7a6bd` process 1043
- Review: passed - exact-tip general reviewer; no findings; checkout clean - human-judgment=not-required
- Reviewed feature tip: 9f5b0a8ed4fe50585e9d5f0708a36b254c5c01c4
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 9f5b0a8ed4fe50585e9d5f0708a36b254c5c01c4
- Accepted main tip: b99b4d781abd6987df006d99009e7fadc59e6592

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl` - `feedback-20260711084422-58b38f3c0681` and `feedback-20260711084506-6941343b941c`; promoted as `promotion-20260711085640-abda9455f963` and `promotion-20260711085640-0231bdc54b1f`

## Candidate Signals While Working

- `feedback-20260711084422-58b38f3c0681`: global help incorrectly depended on a checkout-local loop packet; promoted to state-free help coverage.
- `feedback-20260711084506-6941343b941c`: the user was asked to perform deterministic proof; promoted to the agent-owned mechanical-proof and automated-acceptance contract.

## Final Distillation

- Loop outcome:
  - complete + loop improvement
- Required verification:
  - Retained topology proof: passed - id=dev-a7a6bd; kind=operator_gateway_app-dev; host=beast; roles=operator,gateway,dev; command=exact-tip sync, global help smoke, content digest, overlay, lock, manifest, Caddy, and `/run/php` checks; evidence=Solo process 1043 plus retained Incus inspection recorded in Proof
  - `composer quality-check`: passed - `.orbit/quality-gates/quality-check-2026-07-11T090914Z-aa1da936c214.json`; clean exact tip; 43/43 subgates
- Finalization gate fit:
  - The mixed tooling, PHP, test, skill, and documentation diff requires retained proof and the aggregate quality gate; both passed on the accepted exact tip, and the independent reviewer returned PASS with no findings.
- Distillation packet:
  - Location: `.orbit/loop.md`
  - Includes objective/final diff: yes - Goal, Scope, and exact accepted tip are recorded.
  - Includes worker/reviewer/terminal/evidence pointers: yes - reviewer decision, Solo process, retained topology, quality artifact, feedback events, and promotion ids are recorded.
  - Includes orchestrator steering notes: yes - raw user feedback and its deterministic promotions are retained.
- Agent session capture waivers: Codex - the compact loop retains the exact reviewer verdict and proof receipt without requiring a full raw reviewer-lane capture.
- Fresh analyzer:
  - not used - trigger-only compact-loop policy used deterministic protections and one fresh exact-tip general review; no separate analyzer trigger remained.
- Candidate signals:
  - global state-dependent help -> promote -> state-free global help regression coverage
  - user asked to run mechanical checks -> promote -> agent-owned proof and reviewer-selected automated acceptance coverage
- Accepted durable updates:
  - Both explicit feedback events were promoted into executable regression tests and the active harness, skill, reviewer, hook, and acceptance contracts.
- Rejected or already-covered signals:
  - none - all explicit feedback in this correction produced a justified deterministic protection.
- Deferred follow-ups:
  - none - no known acceptance or cleanup work remains after landing and archive cleanup.
- No-new-signal rationale:
  - The two observed failures are already encoded as promoted protections; no additional analyzer, metric, or ceremony is justified.
