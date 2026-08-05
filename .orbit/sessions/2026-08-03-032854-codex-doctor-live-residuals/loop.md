# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: none
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-doctor-live-residuals`
- Branch: `codex/doctor-live-residuals`

## Goal

Close remaining live Doctor residuals after 231b7024b: safe systemd/launchd
`process.runtime_unit_extra` restore cleanup, robust agent ACL ensure when
optional checkout paths are absent, one canonical force_remote_host token
context for gateway host probes (agent-push stays ordinary), identical
targeted/broad DNS consumer projection semantics, family-scoped
RemoteShellFailed so later families continue, and Hermes reconfigure refreshing
canonical managed process intent.

## Scope

- Owned:
  - `DoctorReportRunner` process extra remove for systemd/launchd with strict
    Orbit-owned identity validation
  - `LocalAgentAclEnsure` optional-path tolerance + staged actionable errors
  - `RemoteLocalExecutor` / host binary / token context for force_remote_host
  - Node DNS projection probe consumer semantics (targeted == broad)
  - Per-family RemoteShellFailed attribution without whole-node abort
  - Related-tool process reconciliation on reconfigure (Hermes command intent)
  - Docs/tests alignment under process/node/doctor/tool surfaces
- Constraints:
  - work only in this prepared worktree
  - no `composer test:e2e*`
  - do not weaken token verification, replay protection, or print secrets
  - no merge/push/deploy; preserve unrelated work and other worktrees
- Out of scope:
  - LAND/merge and retained-topology proof (Codex after landing)
  - arbitrary unit removal outside Orbit-owned identity/path rules

## Proof

- Verification:
  - focused: passed - 576 Internal CLI tests plus focused Doctor, token-context, ACL, DNS projection, process-unit, and tool-reconfigure suites
  - broader: passed - exact clean tip, 43/43 subgates green in `.orbit/quality-gates/quality-check-2026-08-03T012142Z-07726b3a8871.json`
  - runtime: passed - retained topology `dev-da75ed` on Beast; process family healthy in `.orbit/evidence/retained-dev-da75ed-gateway-process-final-tip.stream.jsonl`; the real Caddy payload writer reached reload in `.orbit/evidence/retained-dev-da75ed-gateway-proxy-restore-exact-tip.stream.jsonl` and `.orbit/evidence/retained-dev-da75ed-gateway-proxy-after-write-exact-tip.stream.jsonl` proves global-config drift stayed gone; exact-tip proxy verification reports only the deliberately absent unmanaged Caddy container in `.orbit/evidence/retained-dev-da75ed-gateway-proxy-final-tip.stream.jsonl`; Agent role verification is healthy in `.orbit/evidence/retained-dev-da75ed-agent-role-after-exact-tip.stream.jsonl`; broad family continuation is captured in `.orbit/evidence/retained-dev-da75ed-doctor-all.stream.jsonl`
- Blast radius: complete - evidence=independent repository-wide raw-stdin-reader inventory and InternalExecutorCommand subclass sweep plus Doctor/tool/process/DNS ownership review; result=all 25 protected Internal payload consumers use one buffered boundary, the only remaining raw readers are outside token verification, and no unresolved transport, ownership, docs, or restore surface remains
- Review: passed - exact-tip independent Fable review found no actionable findings; human-judgment=not-required
- Reviewed feature tip: 1d7452df1432248d83dd00ba97e4b7f807fd9a54
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 1d7452df1432248d83dd00ba97e4b7f807fd9a54
- Accepted main tip: 231b7024b9c948f306c63fc5b8313f15486b5c81

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
