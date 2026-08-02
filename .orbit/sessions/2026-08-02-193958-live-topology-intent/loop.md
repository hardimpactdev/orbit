# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: none
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-live-topology-intent`
- Branch: `codex/live-topology-intent`

## Goal

Source-level fixes so OpenClaw gets a distinct managed web runtime on port 8081
(proxy `openclaw.agent` only) while Hermes stays on 8080 (`hermes.agent`);
registered Codex worktree workspace `.env` paths are readable by doctor without
weakening env-path security; and `tool:remove` can safely retire stale `php-cli`
and `php` registry/install state on nodes that do not require those baselines.
Systemd process ExecStart must preserve shell variables so the OpenClaw gateway
token pipeline reaches bash intact.

## Scope

- Owned:
  - `apps/gateway/app/Tools/OpenClawTool.php` (+ focused tool/proxy tests)
  - `apps/gateway/app/Services/Proxy/AgentToolProxyRouteIntent.php`
  - `apps/gateway/app/Services/Processes/SystemdUnitRenderer.php` (ExecStart `$$`)
  - `apps/cli/app/Services/EnvFiles/LocalEnvFileAction.php` + `LocalEnvFilePath.php` + tests
  - `apps/gateway/app/Tools/PhpCliTool.php`, `PhpTool.php` remove path + tests/docs
  - product docs for openclaw/hermes/php-cli/php contracts as needed
  - `PRODUCT_DECISIONS.md` when intent changes
- Constraints:
  - Hermes default web port remains 8080
  - OpenClaw managed web runtime on 8081; Orbit process lifecycle owns it (no
    double supervision with OpenClaw native service)
  - Secure auth on; never log generated tokens; token not in process argv/config set
  - Codex env paths only exact Linux/macOS worktree shapes + symlink escape guards
  - php-cli remove only Orbit-owned install root/symlinks; php remove is registry
    cleanup without deleting shared runtime images
  - No `composer test:e2e*`, no provisioning, no live fleet mutation by the worker
- Out of scope:
  - Live deploy/converge ownership outside retained proof venue (orchestrator)
  - Changing Hermes default port or unrelated tools
  - Beast `node.dns_mapping_mismatch` restore when orbit-dns does not mount the
    managed projection directory (confirmed separate blocker)

## Proof

- Verification:
  - focused: passed - `bin/orbit-cli-pest --compact tests/Feature/InternalEnvFileCommandTest.php` (30 passed); gateway OpenClawTool / PhpCliTool / PhpTool / install preflight / SystemdUnitRenderer tests passed; systemd `$$` ExecStart escape so OpenClaw `${TOKEN_FILE}` / `$(...)` survive unit rendering
  - broader: passed - `.orbit/quality-gates/quality-check-2026-08-02T173417Z-17c49a52df08.json` exit 0, dirty false, commit `496f895cdce72e3b12264fd98ed114668ec02ff5`
  - runtime: passed - retained topology `dev-92f4b4` kind `operator_gateway_agent` host Beast; operator/gateway/agent checkouts `/home/orbit/orbit-run`; proof in `.orbit/evidence/dev-92f4b4-openclaw-runtime-proof.md`
- Blast radius: complete - evidence=repository-wide inventory of SystemdUnitRenderer ExecStart as sole process unit command path plus openclaw/opencode-cli/polyscope/default process command inventory; result=only OpenClaw related process embeds shell dollars; dollar-less commands unchanged under $$ escaping
- Review: passed - human-judgment=not-required - independent follow-up VERDICT PASS; BLAST_RADIUS complete; no actionable findings
- Reviewed feature tip: 496f895cdce72e3b12264fd98ed114668ec02ff5
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 496f895cdce72e3b12264fd98ed114668ec02ff5
- Accepted main tip: b60c0a357cf301d0abcc26103228d16da6efd4a5

### Runtime evidence (retained topology)

- Topology id: `dev-92f4b4`
- Kind: `operator_gateway_agent`
- Host: Beast
- Checkouts: operator, gateway, and agent at `/home/orbit/orbit-run`
- Candidate tip synced: `496f895cdce72e3b12264fd98ed114668ec02ff5`
- OpenClaw: `process:add openclaw-gateway` systemd; tip `80c625b9` failed because systemd consumed `TOKEN_FILE`; fixed at `496f895c`
- After sync/re-add: `http://10.6.0.6:8081` → 200; process restart running and after 8s HTTP 200; logs showed `/home/agent/.openclaw/gateway.token`, preserved `${TOKEN_FILE}` and command substitution, gateway ready/listening; token mode 600 owner agent:agent; systemd active; listener 8081 yes
- php-cli: install then force remove succeeded; `tool:list` empty for that tool
- Artifact: `.orbit/evidence/dev-92f4b4-openclaw-runtime-proof.md`
- Quality: `.orbit/quality-gates/quality-check-2026-08-02T173417Z-17c49a52df08.json`

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
