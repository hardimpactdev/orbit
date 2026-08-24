candidate=7f8ba5b6da0c954bbb480ef6143544513ec420a0

Completed the all-node update review corrections. Did not merge. Did not run or delegate `composer test:e2e*`. Did not edit source or harness tooling for this handoff.

## Candidate

- SHA: `7f8ba5b6da0c954bbb480ef6143544513ec420a0`
- Subject: `Install Agent artifacts for every fleet-update node`
- Worktree dirty=false

## Corrections

- `FleetAgentArtifactProbe` and `FleetUpdateAgentRestartReadiness` now use `isFleetUpdateEligible()`.
- `WorkloadNodeUpdater` installs Agent config/unit payloads for roleless unmanaged fleet targets.
- Ordinary `NodeAgentConfigRenderer::render()` and `NodeAgentServicePayloadBuilder::forNode()` stay on `isAgentEligible()`.
- Fleet-update-only paths are `renderForFleetUpdate()` and `forFleetUpdateNode()`.
- `FleetUpdateTargetSelector` dropped the `instanceof Node` branch and the method-level `redundant-condition` suppression; Eloquent `get()->filter()` keeps a targeted `@mago-expect analysis:invalid-argument`.
- `FleetUpdateAgentVerifier::nodes()` no longer inserts the gateway node.
- `NodeCommandTransportSelector` authorizes only `internal:fleet-update:install-cli` and `internal:fleet-update:verify`.
- `UpdateAllCommand` help and the `update` / `version` related-command lines no longer say "every managed Orbit installation".

## Focused proof

Sequential gateway Pest (no concurrent test process):

- `tests/Feature/Services/Operations/WorkloadNodeUpdaterTest.php` — 40 passed
- `tests/Feature/Services/Operations/FleetVersionProbeTest.php` — 14 passed
- `tests/Unit/Services/NodeCommandTransport/NodeCommandTransportSelectorTest.php` — 13 passed
- `tests/Feature/Services/Operations/FleetUpdateAgentRestartReadinessTest.php` — 1 passed (real final probe with `Http::fake`)

Additional boundary tests: `NodeAgentConfigRendererTest`, `NodeAgentServicePayloadBuilderTest`, `FleetUpdateTargetSelectorTest`. Focused Mago format/lint/analyze on changed PHP.

## Quality-check

`composer quality-check` exit 0 on clean HEAD `7f8ba5b6da0c954bbb480ef6143544513ec420a0`.

- artifact: `.orbit/quality-gates/quality-check-2026-08-23T235032Z-753da568321f.json`
- git.commit=`7f8ba5b6da0c954bbb480ef6143544513ec420a0`
- dirty=false
- duration_seconds=137
- Did not run `composer test:e2e*`

## Proof receipt

Unmodified `bin/orbit-feature-proof-receipt --json` on clean HEAD:

```json
{
    "ok": true,
    "problem": null,
    "candidate": "7f8ba5b6da0c954bbb480ef6143544513ec420a0",
    "dirty": false,
    "docs_only": false,
    "gate": "quality-check",
    "artifact": "/home/nckrtl/orbit/.worktrees/codex-fix-macos-desktop-update-selection/.orbit/quality-gates/quality-check-2026-08-23T235032Z-753da568321f.json",
    "venue": "retained-incus",
    "runtime": "passed - candidate=7f8ba5b6da0c954bbb480ef6143544513ec420a0; venue=retained-incus; environment=dev-fixture plus NMBP macos-27-arm64; target=reachable roleless unmanaged Linux node with current CLI and missing Agent plus native NMBP Desktop staging; expected=the Linux node installs and verifies its missing Agent regardless of roles or management state, the Mac stages verified Desktop plus Agent plus CLI with restart-ready handoff, and unreachable nodes skip before mutation; observed=app-dev-1 was unmanaged with no roles, a current CLI, and no Agent identity, then completed, passed Agent restart readiness and Agent verification, recorded the expected Agent hash, and became current, while NMBP installed isolated CLI and Agent artifacts, staged the Desktop archive, wrote a 0600 restart-ready handoff owned by Desktop, and kept the existing Agent PID unchanged, and offline Linux and Mac retained their platform-specific skip results; result=passed; evidence=`.orbit/evidence/retained-dev-b77c00/runtime-proof.md`"
}
```

Owner-owned runtime re-proof is recorded in `.orbit/loop.md` and `.orbit/evidence/retained-dev-b77c00/runtime-proof.md`. Observed branches:

1. Reachable roleless `managed=false` Linux `app-dev-1` with current CLI and missing Agent completed, passed restart readiness and Agent verification, and became current.
2. Native NMBP staged verified Desktop, Agent, and CLI with a `0600` restart-ready Desktop-owned handoff and an unchanged Agent PID.
3. Unreachable `offline-linux` skipped with `orbit_agent_not_running`; unreachable `offline-mac` skipped with `orbit_desktop_not_running`.

Did not merge.
