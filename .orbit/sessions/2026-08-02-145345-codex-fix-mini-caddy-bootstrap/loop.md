# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: none
- Worktree: /Users/nckrtl/orbit/.worktrees/codex-fix-mini-caddy-bootstrap
- Branch: codex/fix-mini-caddy-bootstrap

## Goal

Restore missing host-mounted orbit-caddy global config together with a
restarting/down managed orbit-caddy in one Doctor repair (seed host global
config before container create/recreate; end only when container is stable and
global config is present), and accept `logs` on internal tool-run payloads so
`tool:logs caddy` returns container logs (or visible remote failure).

## Scope

- Owned:
  - host-mounted global Caddyfile probe/restore (ProxyRouteProbe, ProxyRouteFixer,
    LocalCaddyConfigAction write path for docker-created directory bind sources)
  - `LocalToolRunScriptPayload` allowed action `logs`
  - proxy-doctor + tool-logs contracts and focused Pest coverage
- Constraints:
  - this worktree only; preserve unrelated dirty work; no live Mini mutation;
    no `composer test:e2e*`; no push/merge/release
  - healthy-container global reconciliation (write + reload) must still work
- Out of scope:
  - node.dns_mapping_mismatch repair
  - unrelated tool lifecycle actions
  - broad doctor ordering refactors beyond caddy global+container recovery

## Proof

- Verification:
  - focused: passed - CLI InternalCaddyConfig (directory-as-file bind) + LocalToolRunScriptAction logs allowlist; gateway ProxyRouteProbe host-mounted global probe, ProxyRouteFixer missing-global apply-container path, DoctorReportRunner agent-tool restore fake alignment
  - broader: passed - `composer quality-check` on clean HEAD `e297668c8f128092fedfcfce84167938ef90c89f`; artifact `.orbit/quality-gates/quality-check-2026-08-02T123308Z-18a8fcfce0ee.json`
  - runtime: passed - retained-incus topology id=dev-3acd04 kind=operator_gateway_app-dev host=beast roles=operator,gateway,app-dev; inspected node=app-dev-1 instance=orbit-e2e-dev-3acd04-dev; launcher=./apps/cli/orbit from /home/orbit/orbit-run on orbit-e2e-dev-3acd04-operator; induced missing host `/etc/caddy/Caddyfile` plus same-spec restarting orbit-caddy; doctor detected `proxy.caddy_container_down` + `proxy.global_config_missing`; doctor --restore completed both (`Restored orbit-caddy container` and `Restored host global orbit-caddy config and container`); container running=true restarting=false with host Caddyfile present; verify healthy; `tool:logs caddy --node=app-dev-1` returned container log lines; renamed container failure surfaces `No such container: orbit-caddy`; evidence `.orbit/evidence/retained-caddy-global-bootstrap-dev-3acd04.txt`
- Blast radius: complete - evidence=repository-wide rg of global_config_missing/mismatch, applyContainer, fixGlobalConfig, introspectGlobalConfig, LocalToolRunScriptPayload ALLOWED_ACTIONS under apps/cli apps/gateway packages (excluding vendor); result=host-mounted global probe lives in ProxyRouteProbe; missing-global restore with managed spec uses apply-container via ProxyRouteFixer; container_down already seeds via apply-container; docker-created directory replacement is LocalCaddyConfigAction writeGlobalCaddyfile only; logs action allowlist is LocalToolRunScriptPayload and enables tool:logs remote dispatch; doctor restore fakes updated for probe tag; contracts + command catalog refreshed
- Review: passed - VERDICT=PASS; findings=none; human-judgment=not-required; independent review of feature tip e297668c8f128092fedfcfce84167938ef90c89f
- Reviewed feature tip: e297668c8f128092fedfcfce84167938ef90c89f
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: e297668c8f128092fedfcfce84167938ef90c89f
- Accepted main tip: cf552d1f76e1fb5e8e2d35208ca0caa0de3a06d9

## Status

- State: land
- Blocker: none
- Ready to land: yes
- Landed: feature tip e297668c8f128092fedfcfce84167938ef90c89f merged to main and pushed; live deployment confirmed host config bootstrap and tool:logs; follow-up owns PKI/macOS issues
- Feature tip ready for independent review: e297668c8f128092fedfcfce84167938ef90c89f
- Commits:
  - bfaaeb31e29e9e2f3ca0f171c0e253d7904fb865 Seed host Caddy global config before container recovery
  - e297668c8f128092fedfcfce84167938ef90c89f Align global Caddy probe fakes with host-mounted probe tag
- Retained topology: id=dev-3acd04 kind=operator_gateway_app-dev host=beast release=`composer e2e:incus -- --stop --id=dev-3acd04`

## Feedback

- Events: `.orbit/feedback.jsonl`
