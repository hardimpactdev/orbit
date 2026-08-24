candidate=925138863ad1cdee9281d37e5ca9b038723eb3c8

All-node update selection and pre-mutation skip correction proved.

## Changed files

- PRODUCT_DECISIONS.md
- apps/cli/app/Services/Updates/UpdateAllHumanProgressRenderer.php
- apps/cli/tests/Feature/Services/Updates/UpdateAllHumanProgressRendererTest.php
- apps/docs/content/domains/11_operation/2_update-all/technical/1_update-all.md
- apps/docs/content/domains/11_operation/2_update-all/technical/6.1_update-all_output-render_human.md
- apps/docs/content/domains/11_operation/2_update-all/technical/6.2_update-all_output-render_json.md
- apps/docs/content/domains/11_operation/2_update-all/update-all.md
- apps/docs/content/domains/1_node/node-concepts.md
- apps/gateway/app/Models/Node.php
- apps/gateway/app/Services/NodeCommandTransport/NodeCommandTransportSelector.php
- apps/gateway/app/Services/Operations/FleetUpdateAgentVerifier.php
- apps/gateway/app/Services/Operations/FleetUpdateTargetSelector.php
- apps/gateway/app/Services/Operations/WorkloadNodeUpdater.php
- apps/gateway/app/Services/RemoteShell/LocalExecutorCommandBuilder.php
- apps/gateway/tests/Feature/Services/Operations/FleetUpdateVerifierTest.php
- apps/gateway/tests/Feature/Services/Operations/FleetVersionProbeTest.php
- apps/gateway/tests/Feature/Services/Operations/UpdateRunnerManifestPlanHandoffTest.php
- apps/gateway/tests/Feature/Services/Operations/WorkloadNodeUpdaterTest.php
- apps/gateway/tests/Unit/Services/NodeCommandTransport/NodeCommandTransportSelectorTest.php
- apps/gateway/tests/Unit/Services/Operations/FleetUpdateTargetSelectorTest.php
- apps/gateway/tests/Unit/Services/RemoteShell/LocalExecutorCommandBuilderTest.php
- packages/core/src/Updates/AgentAvailabilityError.php

## Predicate correction

`FleetUpdateTargetSelector` now selects every active non-gateway node with a supported Agent platform and valid WireGuard identity. Roles and stored `managed` no longer control inclusion. Unreachable remotes skip before mutation: `orbit_desktop_not_running` on macOS/Darwin and `orbit_agent_not_running` on other platforms. Reachable selected Macs still receive Desktop staging and pending handoff when the plan has matching assets. Reachable selected Linux nodes receive CLI and Agent artifacts even when roleless and unmanaged. `managed` still means Agent intent for ordinary command transport and Agent service payloads.

## RED

Command:

```
bin/orbit-gateway-pest --compact --filter="updates a roleless unmanaged active node during fleet fan-out|skips an unreachable selected Linux node before mutation|stages a desktop archive and pending automatic handoff for a reachable roleless unmanaged Mac|selects only active Agent-eligible workload nodes"
```

Result: failed, 4 tests, 0 passed, 8 assertions.

- Selector still returned only `eligible-app` and `managed-operator`.
- Roleless unmanaged Mac was not selected (`array has the key 0` failed).
- Unreachable Linux completed instead of skipping with `orbit_agent_not_running`.
- Roleless unmanaged Mac Desktop staging did not complete (`null` vs `completed`).

## GREEN

- `bin/orbit-gateway-pest --compact tests/Feature/Services/Operations/WorkloadNodeUpdaterTest.php` — 38 passed, 374 assertions.
- `bin/orbit-gateway-pest --compact tests/Unit/Services/Operations/FleetUpdateTargetSelectorTest.php` — 2 passed.
- Related verifier, version-probe, transport, and executor tests updated and passing.

## Mago / docs / quality-check

- Focused Mago format/lint/analyze on changed PHP: no new issues.
- `composer docs-lint`: passed (warnings only).
- `composer quality-check`: exit 0 on clean candidate `925138863ad1cdee9281d37e5ca9b038723eb3c8`; artifact `.orbit/quality-gates/quality-check-2026-08-23T222754Z-0a92c35f6441.json`; duration 135s.

## Proof receipt

Impl handoff binds quality-check on clean HEAD with venue `automated` because live Mini/NMBP runtime stays owner-owned. Official `bin/orbit-feature-proof-receipt` reports venue `retained-incus` and correctly refuses to mark runtime passed.

```json
{
    "ok": true,
    "problem": null,
    "candidate": "925138863ad1cdee9281d37e5ca9b038723eb3c8",
    "dirty": false,
    "docs_only": false,
    "gate": "quality-check",
    "artifact": "/home/nckrtl/orbit/.worktrees/codex-fix-macos-desktop-update-selection/.orbit/quality-gates/quality-check-2026-08-23T222754Z-0a92c35f6441.json",
    "venue": "automated",
    "runtime": "not applicable"
}
```

## Remaining runtime proof

Acceptance venue vs `origin/main` is `retained-incus`. Live Mini (Desktop quit → skip) and NMBP (caller-local continue) proof is owner-owned. Do not treat in-memory Pest as runtime passed.

## Risks

- Unmanaged roleless nodes now take fleet-update agent-push for `internal:fleet-update:*` only. Other Agent-dependent commands still require `isAgentEligible()`.
- Agent service payloads remain gated on Agent intent. Unmanaged Linux gets Agent bytes without creating a managed service.
- Did not merge.
