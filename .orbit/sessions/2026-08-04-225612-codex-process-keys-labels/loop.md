# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: none
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-process-keys-labels`
- Branch: `codex/process-keys-labels`

## Goal

Orbit processes gain a durable human display `label` while retaining the
existing stable slug identity internally. Public browser process payloads
expose `key` (the current process name/slug) and `label`. New UI consumers use
`key`; gateway payloads and the SDK may temporarily keep `name` as a deprecated
compatibility alias so current consumers do not break.

## Scope

- Owned:
  - Process model/storage for durable `label` alongside stable slug identity
  - Gateway process API/browser payloads: expose `key` + `label`; keep `name`
    temporarily as deprecated alias where needed
  - CLI: `process:add` optional `--label` (default = key when absent);
    `process:update` accepts `--label` and can still rename identity with
    `--name`; explicit empty `--label=` fails validation
  - Rename semantics: renaming identity does **not** implicitly rewrite a
    custom label
  - Process list / SSE snapshot and lifecycle update semantics with
    snapshot-authoritative label handling on update frames when related process
    key does not match durable event key
  - Authoritative product decision and docs, TDD, implementation across
    gateway/CLI/PHP SDK/TypeScript SDK 0.3.0
- Constraints:
  - Docs and product decision before tests/code; TDD for behavior
  - Preserve unrelated work; never revert others' edits
  - No `composer test:e2e*` in this loop
  - No live fleet mutation
- Out of scope:
  - Release/publish of any package or SDK
  - Live migration / fleet mutation
  - Unrelated Toolbar product work beyond key/label data sufficiency

## Proof

- Verification:
  - focused: passed - process-domain docs lint; ProcessKeyLabelContractTest 6 passed with per-frame SSE label match/fallback; broader gateway process/OpenAPI filters 96 passed (721 assertions) in `.orbit/evidence/process-keys-labels-review-fix-gateway-broader.txt`
  - broader: passed - `composer quality-check` exit 0 dirty=false tip `3e9935542a71756e3547f60bf61cdb6751f56430` artifact `.orbit/quality-gates/quality-check-2026-08-04T205106Z-b7d04291c5cc.json`
  - runtime: passed - gateway Feature Pest (ProcessKeyLabelContractTest + process store/update/list/stream controllers) and monorepo quality-check prove key/label persistence, defaults, rename preservation, list/SSE snapshot key/label, and update-frame label match/fallback on tip `3e9935542a71756e3547f60bf61cdb6751f56430` without live fleet mutation; evidence `.orbit/quality-gates/quality-check-2026-08-04T205106Z-b7d04291c5cc.json`; evidence `.orbit/evidence/process-keys-labels-review-fix-gateway-broader.txt`; evidence `.orbit/evidence/process-keys-labels-blast-radius.txt`
- Blast radius: complete - evidence=`.orbit/evidence/process-keys-labels-blast-radius.txt`; result=process list/stream/store/update payloads, OpenAPI GatewayOpenApi schema, CLI/PHP/TS SDK consumers updated; lifecycle start/stop/restart retain name selectors
- Review: passed - human-judgment=not-required - independent re-review PASS at 3e9935542a71756e3547f60bf61cdb6751f56430; BLAST_RADIUS complete; no actionable findings; full quality-check exit 0
- Reviewed feature tip: 3e9935542a71756e3547f60bf61cdb6751f56430
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 3e9935542a71756e3547f60bf61cdb6751f56430
- Accepted main tip: 8efbef92d68d0a207dcd119b5267bab2e0bb5f59

## Status

- State: land
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
- Relevant prior feedback for surfaces `process-keys-labels` and `process`: none
- Review FIX (prior tip `72ef95be35eae15007f2f87f71ce91d5262a65df`): add/update JSON docs key/label; per-frame SSE label assertions — addressed in `3e9935542`
