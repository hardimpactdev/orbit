# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: none
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-drift-process-hardening`
- Branch: `codex/drift-process-hardening`

## Goal

Finish live topology drift resolution and drift-process hardening as one
contract-first slice: managed tool secret/install consistency, gateway
node-security-posture host boundary, activity/audit secret redaction, structured
fleet version parsing, session archive content-identity reuse, product-decision
correction for shipped `tool:logs`, topology-intent alignment (OpenClaw 18789 /
Hermes 8080), internal tool preflight allowlist, OpenClaw local-prefix
install-cli under agent home, and owner-scoped public capability observation
(`binary_as_user`) so doctor/tool:show match host reality without a world probe
shim.

## Scope

- Owned:
  - Managed OpenClaw/Hermes secret and install helpers + focused tests
  - OpenClaw local-prefix install/update (`install-cli.sh`, PREFIX_BIN) and
    process/token contracts
  - Owner-scoped tool observation (`binary_as_user` on ToolDefinition,
    ToolCatalog, ToolsProbe single/batch probes)
  - Internal tool run-script `preflight` allowlist
  - ActivityLogger SecretSummaryRedactor chokepoint + redactor expansions
  - Fleet update version parsing fail-closed on unstructured output
  - `NodeSecurityPostureProbe` host-boundary transport
  - Session archive content-identity reuse; `PRODUCT_DECISIONS.md` + product docs
  - PEM fixture hygiene so worktree secret scan does not block acceptance
- Constraints:
  - OpenClaw default 18789, Hermes 8080; no 8081 auto-selection; no world
    probe-only shim; presence as agent against PREFIX_BIN
  - Preserve public credential behavior; never print gateway tokens
  - No `composer test:e2e*`; use retained Incus topology for runtime proof
  - Preserve unrelated dirty work; do not revert others' edits
- Out of scope:
  - LAND / merge / archive / worktree cleanup (deferred until after acceptance)
  - UserScopedCli automatic `binary_as_user` adoption (follow-up)
  - Broader managed_secret vs credentialsScript product simplification
  - Live production fleet mutation

## Proof

- Verification:
  - focused: passed - gateway ToolsProbe owner-scoped single/batch executing-shell tests; OpenClawTool local-prefix/probe metadata; ToolCatalog; preflight LocalToolRunScriptAction; ActivityLogger/SecretSummaryRedactor including runtime-assembled PEM fixture without literal private-key header (secret-scan PASS); VersionOutputParser; SystemdUnitRenderer/ManagedToolShell OpenClaw quoting; post-merge RuntimeColdActivation/RuntimeHibernation; docs path lint 0 errors for touched product docs
  - broader: passed - composer quality-check on clean HEAD 66856b245165f4f7969c6a4ae3d59cc079b30bc2 dirty=false overall exit 0 all 43 subgates 0; artifact `.orbit/quality-gates/quality-check-2026-08-03T095612Z-6c14dab744c2.json`
  - runtime: passed - retained-incus id=dev-b6b361 kind=operator_gateway_agent host=beast; synced tip 8113ecdf3 with binary_as_user runtime; doctor --restore agent_user; public tool:install openclaw --with-process; tool:show --live observed_state=installed observed_version OpenClaw 2026.7.1-2; doctor --family=tool --key=tool.capability_missing healthy; host PREFIX_BIN as agent, process active, port 18789 yes 8081 no, token mode 0600 nonblank (hash only), bash -n; whitespace reconfigure hash change + redacted activity; release + second stop not_found; evidence `.orbit/evidence/retained-drift-hardening-proof.txt`. Runtime-proven OpenClawTool.php and ToolsProbe.php remain byte-identical through final merged tip 66856b245 (ToolCatalog PHPDoc-only and PEM fixture/main merge do not change those runtime files); main integrated tip d4f18bfedb86d12c841f141ce61cdccd64188888 via non-destructive merge with PRODUCT_DECISIONS preserving both drift-hardening and runtime-activation intents plus main session archive.
- Blast radius: complete - evidence=repository-wide rg inventory of probeMetadata/binary_as_user/toolCapabilityProbe/ToolsProbe introspect paths, OpenClaw install-cli/PREFIX_BIN, LocalToolRunScriptPayload ALLOWED_ACTIONS, ActivityLogger/SecretSummaryRedactor PEM/Bearer rules, VersionOutputParser, RuntimeActivation* overlap under merge, secret-scan candidate-diff rules for private-key-header under apps/gateway apps/cli packages apps/docs PRODUCT_DECISIONS bin (excluding vendor); result=owner-scoped presence is optional metadata consumed only by ToolsProbe single/batch capability scripts; OpenClaw declares binary_as_user=agent with PREFIX_BIN path retained; generic absolute/PATH probes unchanged without metadata; UserScopedCli not auto-adopted; PEM redaction tests assemble headers at runtime so scanner stays strict; main merge preserves soft/cold activation contracts and archived session/index without dropping preflight/OpenClaw observation; docs/product decisions record local-prefix install and no world probe shim
- Review: passed - reviewer Fable - human-judgment=not-required - VERDICT=PASS - final re-review of exact merged feature tip 66856b245165f4f7969c6a4ae3d59cc079b30bc2
- Reviewed feature tip: 66856b245165f4f7969c6a4ae3d59cc079b30bc2
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 66856b245165f4f7969c6a4ae3d59cc079b30bc2
- Accepted main tip: d4f18bfedb86d12c841f141ce61cdccd64188888

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
