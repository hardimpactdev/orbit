# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: none
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-hermes-credential-refresh`
- Branch: `codex/hermes-credential-refresh`

## Goal

After a successful `tool:reconfigure`, when the catalog provides a
`credentialsScript`, Orbit executes it (action `credentials`), parses a valid
JSON object, and replaces `NodeTool` stored credential fields so
`tool:credentials` returns actual values (e.g. Hermes dashboard password from
`/home/agent/.hermes/dashboard.password`) rather than stale placeholders.
Credential values never appear in reconfigure success output or logs. Credential
refresh failure fails the reconfigure honestly. Tools without credential scripts
and related-process restart semantics stay intact.

## Scope

- Owned:
  - `apps/gateway/app/Services/Tools/ToolReconfigurer.php`
  - `apps/gateway/tests/Unit/Services/Tools/ToolRemoteShellTransportTest.php`
  - `apps/docs/content/domains/3_tool/12_tool-reconfigure/**`
  - `apps/docs/content/domains/3_tool/catalog/hermes.md`
  - `apps/docs/content/generated/command-catalog.json`
- Constraints:
  - Work only in this worktree; preserve unrelated dirty work elsewhere
  - Do not run `composer test:e2e*`
  - No production fleet mutation; retained disposable topology only for proof
  - No build/release
- Out of scope:
  - Install/update soft-fail credential capture semantics
  - Fixing retained topology agent-home traverse for credentials scripts
  - Production live reconfigure (orchestrator may re-proof post-land)

## Proof

- Verification:
  - focused: passed - `bin/orbit-gateway-pest --compact tests/Unit/Services/Tools/ToolRemoteShellTransportTest.php` (13 passed, 110 assertions) on merge tip after integrating main; focused gateway Mago clean of new errors on `ToolReconfigurer` and transport tests at feature commit
  - broader: passed - `composer quality-check` exit 0 at clean merge tip `d2f3bdba49482212335f0e058b23194bfa9da7dc`; artifact `.orbit/quality-gates/quality-check-2026-08-02T210933Z-62f6739df6ec.json` (feature commit e64389ee7 also had exit 0)
  - runtime: passed - retained topology `dev-92f4b4` kind `operator_gateway_agent` host Beast; synced feature tip to `/home/orbit/orbit-run`; seeded stale hermes credentials then `ToolReconfigurer::reconfigure(hermes, agent-1)` returned reconfigured with process restarted; stored fields no longer the install-time generated-password placeholder; success payload without password values; evidence `.orbit/evidence/dev-92f4b4-hermes-credential-refresh-runtime-proof.md`
- Blast radius: complete - evidence=repo inventory of ToolReconfigurer credentialsScript path, ToolInstaller/ToolsFixer credential store shape, tool:reconfigure and hermes catalog docs, ToolRemoteShellTransportTest coverage, retained topology reconfigure exercise; result=single reconfigure-owned credential refresh after successful setup with honest failure before related-process restart
- Review: passed - human-judgment=not-required; independent review PASS on feature commit `e64389ee7a68afc63b5fbf94524f0350ad509d0d` with no actionable findings; clean merge of main `e18028f9e5fcdca7f2ea17bf2b8f9a23d1340aa8` (orphaned proxy-remove only) into this tip with focused re-prove green
- Reviewed feature tip: d2f3bdba49482212335f0e058b23194bfa9da7dc
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: d2f3bdba49482212335f0e058b23194bfa9da7dc
- Accepted main tip: e18028f9e5fcdca7f2ea17bf2b8f9a23d1340aa8

## Status

- State: land
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
