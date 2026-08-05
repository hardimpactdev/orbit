# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: none
- Worktree: /Users/nckrtl/orbit/.worktrees/codex-fix-live-topology-drift
- Branch: codex/fix-live-topology-drift

## Goal

Recover managed orbit-caddy containers stuck in a Docker restart loop even when
stored spec hash and network match, and surface combined stdout/stderr when
tool log reads fail (including docker logs redirected with 2>&1).

## Scope

- Owned:
  - `apps/cli/app/Services/Caddy/LocalCaddyConfigAction.php` (apply-container
    treats restarting as unhealthy and forces replace; validates stable running)
  - `apps/gateway/app/Services/Proxy/ProxyRouteFixer.php` (container-down restore
    wording/path through apply-container)
  - `apps/gateway/app/Services/Tools/ToolLogReader.php` (failure output preserves
    useful stdout when stderr empty)
  - focused Pest coverage + proxy/tool-logs contract docs + generated command catalog
- Constraints:
  - preserve unrelated dirty work; no live topology mutation; no e2e composer
    lanes; no push
  - stopped-container path remains start-only when hash/network match
- Out of scope:
  - fixing the underlying bad Caddyfile that caused the loop
  - mutating live Mini topology from this slice
  - applying failureOutput fallback to non-logs tool lifecycle actions

## Proof

- Verification:
  - focused: passed - CLI InternalCaddyConfig restarting/stopped apply tests; gateway ToolLogReaderTest + ProxyRouteFixer down-path assertions
  - broader: passed - composer quality-check on clean HEAD 096c453a6719115d3951f11874d8190e141ae71b; artifact `.orbit/quality-gates/quality-check-2026-08-02T113128Z-55a647c47542.json`
  - runtime: passed - retained-incus topology id=dev-356fea kind=operator_gateway_app-dev host=beast roles=operator,gateway,app-dev; inspected node=app-dev-1 instance=orbit-e2e-dev-356fea-dev; launcher=./apps/cli/orbit from /home/orbit/orbit-run on orbit-e2e-dev-356fea-operator; induced same-spec restarting orbit-caddy; doctor --family=proxy --node=app-dev-1 detected proxy.caddy_container_down; doctor --restore completed Restored orbit-caddy container on app-dev-1 and recreated caddy:2-alpine with running=true restarting=false; verify healthy; evidence `.orbit/evidence/retained-caddy-restart-loop-dev-356fea.txt`
- Blast radius: complete - evidence=repository-wide rg inventory of applyContainer/startContainer/fixCaddyContainer/caddy_container_down callers and ToolLogReader/remoteActionFailed stderr patterns under apps/cli apps/gateway packages (excluding vendor); result=caddy restart-loop recreate lives only in LocalCaddyConfigAction apply-container and is reached by ProxyRouteFixer (proxy.caddy_container_down/missing/detached), ToolsFixer caddy repair, and AppProxyRouteCaddyInstaller via RemoteCaddyConfig; start-only path remains for unmanaged tool records; ToolLogReader failureOutput is logs-only and does not change other tool lifecycle stderr-only failure paths; proxy-doctor and tool-logs contracts + generated command catalog updated for the new test mapping
- Review: passed - VERDICT=PASS; findings=none; human-judgment=not-required; independent review of feature tip 096c453a6719115d3951f11874d8190e141ae71b
- Reviewed feature tip: 096c453a6719115d3951f11874d8190e141ae71b
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 096c453a6719115d3951f11874d8190e141ae71b
- Accepted main tip: 6a80119bde49eb537ee4337711d016a54a87323a

## Status

- State: accepted
- Blocker: none
- Ready to land: yes
- Feature tip ready for independent review: 096c453a6719115d3951f11874d8190e141ae71b
- Commits:
  - 0fc7cd8a180e40edc12992a283ccaad6777b4913 Recover restarting orbit-caddy and keep tool log failures
  - 096c453a6719115d3951f11874d8190e141ae71b Refresh command catalog for tool-logs test mapping
- Retained topology: id=dev-356fea kind=operator_gateway_app-dev host=beast release=composer e2e:incus -- --stop --id=dev-356fea

## Feedback

- Events: .orbit/feedback.jsonl
