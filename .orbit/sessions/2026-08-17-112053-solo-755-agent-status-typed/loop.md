# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `solo://proj/2/todo/i09-a-preserve-typed--755`
- Worktree: `/Users/nckrtl/orbit/.worktrees/solo-755-agent-status-typed`
- Branch: `solo-755-agent-status-typed`

## Goal

The agent `/status` endpoint serializes tagged configuration-availability and gateway-connection states instead of a flattened label string plus nullable fields, and the macOS Agent decodes those typed states and renders labels in the UI, eliminating `starts_with("Connected")` parsing and duplicated `Disconnected: Disconnected:` labels; round-trip is proven for connected, disconnected, missing-config, and invalid-config.

## Scope

- Owned: apps/agent Rust service status DTO/endpoint (ServiceStatusSnapshot / ConnectionStatus serialization) and apps/macos decoder/renderer (menu-state mapping and label rendering), plus producer and consumer Rust tests; primitive=typed Agent service status across the local HTTP boundary; transitions=success:gateway reachable with loaded config serializes a tagged connected state that the macOS side renders as Connected|failure:missing or invalid config serializes a tagged missing-config or invalid-config state rendered once with no duplicated label|retry:re-fetching /status re-derives the current tagged state from config load and gateway ping|stop-restart:agent restart re-derives the snapshot from disk config and a fresh ping|stale:gateway unreachable serializes a tagged disconnected state carrying the reason
- Constraints: Rust apps/agent and apps/macos only; change the local JSON wire shape atomically with matching producer and consumer round-trip tests; no gateway/CLI/PHP behavior change; cargo fmt, clippy, and tests green.
- Out of scope: gateway API, CLI, PHP behavior, topology or fleet changes, and macOS UI redesign beyond typed-state label rendering.

## Proof

- Verification:
  - focused: passed - TDD RED (new tagged types/mapper did not compile) then GREEN; `cargo test` producer round-trip (`service_status_snapshot_round_trips_*`, 4 states) and consumer decode+label (`menu_state_from_tagged_*`, 4 states) pass natively on darwin
  - broader: passed - `composer quality-check` on exact clean candidate `4c057d8f81c77adfb505234ed393183074522fae` exited zero with all 45 subgates zero including native agent+macos cargo check/clippy/fmt/test; receipt=`.orbit/quality-gates/quality-check-2026-08-17T091359Z-1f04b1f39b0e.json` (sha256 `43bbbe04778c8f8de206189840bd811e996bac4098ff0b24b6cbf95f3c391c75`)
  - runtime: passed - candidate=4c057d8f81c77adfb505234ed393183074522fae; venue=host-macos; environment=dev-fixture; command=cargo test agent and macos; expected=agent /status serializes tagged config-availability and gateway-connection states and the macOS decoder renders one label per state with no doubled prefix; observed=native darwin cargo tests pass for connected, disconnected, missing-config, and invalid-config with tagged wire JSON carrying no display labels and single rendered labels; result=passed; evidence=`.orbit/evidence/solo-755-host-macos-receipt.md`
- Blast radius: complete - evidence=repository-wide `rg` for ServiceStatusSnapshot, config_loaded, GatewayConnection, build_service_status_snapshot, and the starts_with parse; result=all references confined to apps/agent/src/lib.rs, apps/agent/src/http.rs, apps/macos/src/main.rs (3 files; lib.rs and main.rs changed, http.rs unchanged but compiles against the new enum); no external consumers
- Review: passed - human-judgment=not-required; reviewer=fresh Solo Claude 2474; BLAST_RADIUS=complete; independently verified tagged serde round-trips for all 4 states with no wire labels, macOS decoder single-label mapping with the string-parse and double-wrap removed, http.rs /status compiles unchanged against the new enum, meaningful tests, and confirmed the remaining config_loaded references belong to a separate DashboardConfig Tauri DTO; no defects
- Reviewed feature tip: 4c057d8f81c77adfb505234ed393183074522fae
- Acceptance venue: host-macos
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 4c057d8f81c77adfb505234ed393183074522fae
- Accepted main tip: 55846af068503c79e9cb563a9e1abbcd96219771

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
concrete reason` or `complete - evidence=repository-wide search, inventory, or
lintable check; result=summary` before acceptance; `gaps` returns to BUILD.
For stateful, lifecycle, or concrete UX work, optionally append one compact
clause on the existing Scope `Owned` row (do not add a permanent new row):
`primitive=exact requested primitive; transitions=success:terminal success|failure:terminal failure|retry:retry behavior|stop-restart:stop or restart|stale:stale-state or n/a`.
Omit the clause for ordinary/local changes. When `primitive=` or `transitions=`
is present, deterministic lint requires both fields, the five known transition
keys without duplicates or empty values, and rejects template placeholders; it
does not grade prose or decide whether the feature is stateful. Explicit `n/a`
values are fine when a transition does not apply. After FRAME, run
`bin/orbit-feature-acceptance route` for the read-only
diff-derived venue before expensive PROVE work. For non-`automated` venues,
`Verification.runtime: passed` must use one candidate-bound structured receipt
on that same single line. Required fields are candidate=, venue=, environment=,
expected=, observed=, result=passed, and evidence= as one exact inline-code path
under the worktree evidence or quality-gates trees. Use exactly one of target= or command=.
Live/production claims require exact environment=live; ordinary retained
topology may use environment=dev-fixture. Semicolons separate fields,
so values must not embed raw semicolon-delimited pseudo-fields. Known keys
only. Example evidence citation: write a real receipt and cite one exact regular
file below the worktree evidence tree (not a directory root). A failed,
excluded, still-required, or deferred final hop cannot be recorded as passed;
remain in PROVE, disarm any armed or recorded acceptance, and follow FIX ->
BUILD -> PROVE before ACCEPT. Keep a still-valid Review and Reviewed feature tip
on proof-only retries; a HEAD change still needs a refreshed review. Automated
venues keep `runtime: not applicable`. Proof files retained by the compact
archive must be cited as one exact inline-code path; prose, directories, padded
code spans, and partial paths are not proof citations.
