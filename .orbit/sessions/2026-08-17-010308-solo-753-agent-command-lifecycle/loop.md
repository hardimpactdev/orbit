# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `solo://proj/2/todo/i09-b-give-agent-buf--753`
- Worktree: `/Users/nckrtl/orbit/.worktrees/solo-753-agent-command-lifecycle`
- Branch: `solo-753-agent-command-lifecycle`

## Goal

Buffered and streaming Agent commands share one owned spawn lifecycle that starts timeout monitoring immediately after spawn, writes stdin concurrently, and always kills and reaps unfinished children without changing either endpoint wire contract.

## Scope

- Owned: `apps/agent/src/http.rs`, focused Rust lifecycle tests, and product authority only if reconciliation finds a contract correction is required; primitive=one owned spawned-command lifecycle; transitions=success:normal child exit is observed and reaped|failure:spawn input wait or timeout failure terminates and reaps the child|retry:n/a|stop-restart:dropping a streaming body cancels then kills and reaps the child|stale:n/a
- Constraints: Start the timeout clock immediately after spawn; write stdin concurrently with stdout and stderr draining; keep buffered and streaming output policies separate; preserve buffered JSON and process-stream v1 wire contracts; record literal RED and GREEN evidence; never run `composer test:e2e*`.
- Out of scope: Gateway, CLI, SDK, macOS tray UI, public command contract changes, E2E execution, primary-checkout edits, Solo todo mutation, merge, push, acceptance, archive, and cleanup.

## Proof

- Verification:
  - focused: passed - literal RED buffered unread-stdin timeout failure at `.orbit/evidence/todo-753-red-unread-stdin-actual/transcript.txt`; literal RED streaming unread-stdin blocked-response failure at `.orbit/evidence/todo-753-red-stream-unread-stdin-actual/transcript.txt`; GREEN unread-stdin pair at `.orbit/evidence/todo-753-green-unread-stdin-actual/transcript.txt`; candidate `cargo fmt --manifest-path apps/agent/Cargo.toml -- --check`, `cargo test --manifest-path apps/agent/Cargo.toml` (47 passed), `cargo check --manifest-path apps/agent/Cargo.toml`, and `cargo clippy --manifest-path apps/agent/Cargo.toml --all-targets -- -D warnings` passed
  - broader: passed - exact clean candidate `c515edbd2c4f8562c1e5452065273ca41ac66973` passed root `composer quality-check`; 10 units and all recorded subgates passed; evidence=`.orbit/quality-gates/quality-check-2026-08-16T224856Z-4da6b630afbc.json`
  - runtime: passed - candidate=c515edbd2c4f8562c1e5452065273ca41ac66973; venue=retained-incus; environment=dev-fixture; target=orbit-e2e-dev-605b22-agent; expected=exact candidate passes the Agent lifecycle suite in the routed retained Agent environment; observed=matching source hash and 47 Agent tests passed including timeout during unread stdin dropped stream kill and reap; result=passed; evidence=`.orbit/evidence/todo-753-retained-incus-runtime.txt`
- Blast radius: complete - evidence=repository-wide diff and production lifecycle inventory; result=only apps/agent/src/http.rs changed and production contains one SpawnedCommand one Command::new(binary) one stdin write site and one terminate_child path
- Review: passed - human-judgment=not-required; independent local Solo reviewer found no actionable findings after checking lifecycle races, wire consumers, tests, and exact-candidate evidence.
- Reviewed feature tip: c515edbd2c4f8562c1e5452065273ca41ac66973
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: c515edbd2c4f8562c1e5452065273ca41ac66973
- Accepted main tip: 988cd256b9e0f178bf98c647ae135706842f7c7c

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
