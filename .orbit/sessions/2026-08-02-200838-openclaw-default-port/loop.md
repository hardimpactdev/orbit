# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: none
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-openclaw-default-port`
- Branch: `codex/openclaw-default-port`
- Feature tip: `3a40b2b244d61daffe98b8e9da59879ab2c2141c`

## Goal

Correct OpenClaw managed web port contract from hard-coded 8081 to OpenClaw's
documented default 18789, keep Hermes on 8080, and route openclaw.agent-style
tool hostnames to host.docker.internal:18789 without touching Orbit Caddy's
private backend 8081 or unrelated work.

## Scope

- Owned:
  - `apps/gateway/app/Tools/OpenClawTool.php`
  - `apps/gateway/app/Services/Proxy/AgentToolProxyRouteIntent.php`
  - OpenClaw and Hermes port contract tests under `apps/gateway/tests/`
  - `apps/docs/content/domains/3_tool/` OpenClaw and Hermes port prose
  - `PRODUCT_DECISIONS.md` correction entry
- Constraints:
  - Preserve token/auth/external-supervisor/systemd-dollar fixes
  - No native OpenClaw service ownership
  - Do not change `OrbitCaddyContainer::PrivateBackendPort` (8081)
  - Preserve all unrelated work; no reverts
- Out of scope:
  - Final tool-owned proxy convergence after candidate deployment
  - Landing

## Proof

- Verification:
  - focused: passed - `bin/orbit-gateway-pest --compact` on OpenClawTool, SystemdUnitRenderer, OrbitCaddyContainerRouteCoverage, ProxyRouteProbe, ProxyRouteFixer, ToolInstallController, ToolsProbe, ToolsFixer, DoctorReportRunner (345 tests, 2359 assertions)
  - broader: passed - `composer quality-check` exit 0; bound tip `3a40b2b244d61daffe98b8e9da59879ab2c2141c` with dirty=false; summary `.orbit/quality-gates/quality-check-2026-08-02T180243Z-1d7c7769b005.json`
  - runtime: passed - retained Incus topology dev-92f4b4 (kind operator_gateway_agent, host Beast) with exact source at /home/orbit/orbit-run showing WEB_PORT=18789; recreated openclaw-gateway on agent-1 with external supervision/token file and --port 18789; direct http://10.6.0.6:18789 returned 200; process:restart returned running; after 8 seconds HTTP returned 200; production Agent OpenClaw also recreated on 18789 with gateway-ready logs (proxy convergence after deployment); evidence `.orbit/evidence/openclaw-default-port-runtime-proof.txt`
- Blast radius: complete - evidence=tip touches WEB_PORT+proxy intent+catalog/README+PRODUCT_DECISIONS+all OpenClaw 8081 assertions; rg shows no OpenClaw host.docker.internal:8081 or gateway.port/run --port 8081 in product runtime; OrbitCaddy PrivateBackendPort 8081 and non-agent 8081 tests remain; result=all OpenClaw default-port consumers updated, Caddy private 8081 preserved, no OpenClaw-specific 8081 source orphan
- Review: passed - human-judgment=not-required; VERDICT PASS; no actionable findings; nonblocking ops note that existing stored process rows are sticky and need explicit recreate (already done live)
- Reviewed feature tip: 3a40b2b244d61daffe98b8e9da59879ab2c2141c
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 3a40b2b244d61daffe98b8e9da59879ab2c2141c
- Accepted main tip: 9336308c194f1f689c50a3696b9dad28c2256395

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
reason` or `complete - evidence=...; result=...` before acceptance; `gaps`
returns to BUILD. Proof files retained by the compact archive must be cited as
one exact inline-code path under .orbit/evidence or .orbit/quality-gates;
prose, directories, padded code spans, and partial paths are not proof citations.
