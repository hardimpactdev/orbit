# Solo 755 host-macos runtime receipt

candidate=`4c057d8f81c77adfb505234ed393183074522fae`
venue=host-macos
environment=dev-fixture
host=implementing macOS (darwin) — native cargo execution, not Incus
quality_receipt=`.orbit/quality-gates/quality-check-2026-08-17T091359Z-1f04b1f39b0e.json` (commit 4c057d8f, dirty=false, exit 0, all 45 subgates zero; native `agent_cargo_{check,clippy,fmt,test}` and `macos_cargo_{check,clippy,fmt,test}` all 0)

## What changed

The agent `/status` handler is `Json(build_service_status_snapshot())`. The wire DTO
`ServiceStatusSnapshot` is now a serde-tagged enum `#[serde(tag="kind", rename_all="snake_case")]`
with variants `loaded { gateway_ip, node_ip?, node_name?, connection }`, `missing_config { path }`,
`invalid_config { reason }`, where `connection` is `GatewayConnection` `#[serde(tag="state")]` =
`connected` | `disconnected { reason }`. No display labels are serialized. The macOS consumer
(`menu_state_from_service_status_snapshot`) matches the tagged enum and renders labels only in the
UI, replacing `snapshot.connection.starts_with("Connected")` string-parsing and the
`ConnectionStatus::Disconnected(snapshot.connection)` double-wrap that produced
`Disconnected: Disconnected:`.

## Native proof (macOS, this Mac)

| State | Producer (apps/agent) tagged /status body asserted | Consumer (apps/macos) rendered label | Result |
|---|---|---|---|
| connected | `{"kind":"loaded",...,"connection":{"state":"connected"}}` | `Connected` (single) | PASS |
| disconnected | `{"kind":"loaded",...,"connection":{"state":"disconnected","reason":"gateway returned HTTP 503"}}` | single `Disconnected: ...`, NOT doubled | PASS |
| missing-config | `{"kind":"missing_config","path":"..."}` | single missing-config label, NOT doubled | PASS |
| invalid-config | `{"kind":"invalid_config","reason":"..."}` | single invalid-config label, NOT doubled | PASS |

- `cargo test` producer round-trip (`service_status_snapshot_round_trips_*`): 4 passed on darwin.
- `cargo test` consumer decode+label (`menu_state_from_tagged_*`): 4 passed on darwin. Each asserts
  `!label.contains("Disconnected: Disconnected:")`.
- Producer tests also assert the encoded wire text contains no `"Connected"`/`"Disconnected:"` display
  strings (labels are UI-only).
- `cargo clippy --all-targets -- -D warnings` and `cargo fmt --check` green for both crates
  (quality-check subgates).

## Regression context (live agent on this Mac)

The currently-installed (pre-candidate build) Orbit Agent answered `GET http://127.0.0.1:9477/status`
with the OLD flattened bag `{"connection":"Connected","gateway_ip":"10.6.0.2","node_ip":"10.6.0.8","node_name":"mini","config_loaded":true}`
— exactly the flattened, label-bearing shape this todo replaces. The candidate binary was not bound to
9477 to avoid disrupting that running agent; the candidate `/status` body is proven by the native tests
above (the handler is a thin `Json(build_service_status_snapshot())`). Re-deploying the rebuilt agent is
the operator's install step and out of scope for landing the source change.

result=passed
