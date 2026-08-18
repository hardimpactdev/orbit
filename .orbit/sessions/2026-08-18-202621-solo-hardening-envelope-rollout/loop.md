# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad:
- Worktree: /Users/nckrtl/orbit/.worktrees/solo-hardening-envelope-rollout
- Branch: solo-hardening-envelope-rollout

## Goal

Remaining gateway `fromJsonEnvelope` consumers that gate convergence, remediation, or writes fail closed on a malformed or truncated remote success envelope: protocol violation becomes halt/unreachable, never an empty-state write.

## Scope

- Owned: `apps/gateway` remote-envelope consumers and their Pest coverage; `apps/docs/content/tech-stack.md` fail-closed probe contract.
- Constraints: follow ManagedFile/SystemdService mapping from commit 75e939bae; no behavior change on well-formed envelopes; never run `composer test:e2e*`; do not merge or push.
- Out of scope: live-node verification; E2E lanes; merging to main.

## Proof

- Verification:
  - focused: passed - `bin/orbit-gateway-pest --compact tests/Unit/Services/RemoteShell/RemoteEnvelopeFailClosedTest.php` plus converted-consumer suites green on merged tip edf338ca7
  - broader: passed - `composer quality-check` on clean merged commit edf338ca75eed2c134901004b799ecd5d3115c37 exit 0, 45/45 subgates (`.orbit/quality-gates/quality-check-2026-08-18T182538Z-84a42510b8ee.json`); pre-merge full gateway suite 7013 passed 2 skipped
  - runtime: passed - candidate=edf338ca75eed2c134901004b799ecd5d3115c37; venue=retained-incus; environment=dev-fixture; target=orbit-e2e-dev-0e0eb4-gateway; expected=exact candidate maps malformed remote envelopes to halt or unreachable outcomes across converted consumers without remediation in the routed retained gateway environment; observed=matching RemoteShellSuccessData sha256 e112296e2e67 and 70 tests passed 193 assertions in the retained gateway instance; result=passed; evidence=`.orbit/evidence/solo-hardening-envelope-rollout-retained-incus-runtime.txt`
- Blast radius: complete - evidence=exhaustive rg fromJsonEnvelope call-site inventory with per-site decisions recorded in the handoff; result=21 decision-gating consumers converted to fromJsonEnvelopeOrFail with halt-not-remediate mappings, 3 display-only callers kept lenient with PHPDoc justification, lenient parser retained for those callers, full gateway suite 7013 passed
- Review: passed - orchestrator Claude reviewer VERDICT PASS: conversions follow the 744 ManagedFile/SystemdService pattern, spot-checked DeployManager (failed step not empty success) and RemoteSecretFile (throws before callback), deliberate exceptions justified (RemoteCaddyConfig null-halt, raw public-key and key=value fallbacks preserved for live non-JSON emitters), doctor drift key change instance.path_missing to instance.remote_shell_probe_failed is the honest unverifiable signal; human-judgment=not-required
- Reviewed feature tip: edf338ca75eed2c134901004b799ecd5d3115c37
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: edf338ca75eed2c134901004b799ecd5d3115c37
- Accepted main tip: bda12d7e789529b26c140b536a028d562c18fce4

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
