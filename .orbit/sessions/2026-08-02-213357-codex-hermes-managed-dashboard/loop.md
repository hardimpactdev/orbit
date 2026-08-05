# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: none
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-hermes-managed-dashboard`
- Branch: `codex/hermes-managed-dashboard`

## Goal

Hermes on the agent node is Orbit-managed: install/update/reconfigure converge `orbit-hermes-dashboard` on port 8080 with resolved hermes.NODE_TLD public URL and real basic-auth credentials; unmanaged stop only when unit is not active/activating/reloading; reconfigure restarts related process so URL/env take effect; tool:remove tears down related process by name+tool for all process-backed tools with surfaced runtime-unit warnings.

## Scope

- Owned: Hermes tool/process/auth, related-process remove/reconfigure lifecycle, tool-remove/reconfigure docs and catalogs (openclaw/hermes/opencode/polyscope), tests, `.orbit/loop.md`, retained evidence
- Constraints: secret safety; no E2E composer lanes; preserve unrelated work
- Out of scope: fixing retained agent-push/preflight substrate on this topology; OpenClaw redesign; Caddy core; Hermes upstream

## Proof

- Verification:
  - focused: passed - related-process remove (hermes + openclaw), tool-mismatch non-remove, Hermes ActiveState guard, reconfigure restart
  - broader: passed - composer quality-check exit 0 at clean HEAD 4ef1aee14c8d6737f362279cd0dc616182abf7f8 (git.dirty=false); artifact `.orbit/quality-gates/quality-check-2026-08-02T191905Z-b250c137471e.json`
  - runtime: passed - retained topology dev-92f4b4 kind operator_gateway_agent host Beast; checkouts /home/orbit/orbit-run; process orbit-hermes-dashboard active and restart stays running; Host hermes.agent HTTP 302 /login; credential files mode 600 owner agent:agent; ActiveState active skips unmanaged stop, PID stable; evidence `.orbit/evidence/dev-92f4b4-hermes-managed-dashboard-runtime-proof.md`
- Blast radius: complete - evidence=repository inventory of HermesTool, RelatedToolProcessRemover, ToolRemover/Reconfigurer, AgentToolProxyRouteIntent WEB_PORT, catalogs openclaw/hermes/opencode/polyscope, tool-remove/reconfigure docs, focused Pest, retained topology proof; result=one coherent related-process lifecycle for process-backed tools with Hermes managed dashboard on 8080
- Review: passed - human-judgment=not-required
- Reviewed feature tip: 4ef1aee14c8d6737f362279cd0dc616182abf7f8
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 4ef1aee14c8d6737f362279cd0dc616182abf7f8
- Accepted main tip: 72f07fd48846cc0c71ee52ad34de0b4329f25761

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`

## Runtime notes

- Retained `tool:install hermes` blocked by known agent runtime_user preflight / agent-push false negative; documented in evidence; live fleet is true reconfigure venue.
- Operator used `http://10.6.0.2` against recreated `orbit-gateway-e2e-topology-lease-http` during proof.
