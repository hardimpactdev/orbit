candidate=034cfb84fe1e644ae8254fdb3b7dd2b47d672fe6

Reverted the rejected harness commit. Feature tree content matches intended `74acf19745ef1988ac835a2ecedfb52f10fc2e25`. Did not merge.

## Revert

- Revert commit: `034cfb84fe1e644ae8254fdb3b7dd2b47d672fe6` (`git revert --no-edit 925138863ad1cdee9281d37e5ca9b038723eb3c8`)
- Rejected SHA: `925138863ad1cdee9281d37e5ca9b038723eb3c8` (`Derive feature proof receipts from the tracking branch`)
- Worktree dirty=false after revert and after quality-check
- `git diff 74acf19745ef1988ac835a2ecedfb52f10fc2e25 HEAD` is empty
- `git diff origin/main...HEAD -- bin/orbit-loop-contract.php bin/orbit-worker-handoff` is empty

## Changed files versus `origin/main`

No effective harness changes. Feature source/docs/tests only:

- PRODUCT_DECISIONS.md
- apps/cli/app/Services/Updates/UpdateAllHumanProgressRenderer.php
- apps/cli/tests/Feature/Services/Updates/UpdateAllHumanProgressRendererTest.php
- apps/docs/content/domains/11_operation/2_update-all/technical/1_update-all.md
- apps/docs/content/domains/11_operation/2_update-all/technical/6.1_update-all_output-render_human.md
- apps/docs/content/domains/11_operation/2_update-all/technical/6.2_update-all_output-render_json.md
- apps/docs/content/domains/11_operation/2_update-all/update-all.md
- apps/docs/content/domains/1_node/node-concepts.md
- apps/docs/content/tech-stack.md
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

Exact proof-route against `origin/main`: 23 files, venue=`retained-incus`.

## Quality-check

`composer quality-check` exit 0 on clean HEAD `034cfb84fe1e644ae8254fdb3b7dd2b47d672fe6`.

- artifact: `.orbit/quality-gates/quality-check-2026-08-23T223248Z-d4fa8fce40b8.json`
- git.commit=`034cfb84fe1e644ae8254fdb3b7dd2b47d672fe6`
- dirty=false
- duration_seconds=134
- Did not run `composer test:e2e*`

## Proof receipt

Unmodified `bin/orbit-feature-acceptance route` on this HEAD: 23 files, `base=main`=`origin/main`=`9d9d91c093136c33de09085ff2d4fffcc45cea44`, venue=`retained-incus`.

Unmodified `bin/orbit-feature-proof-receipt --json` on clean HEAD:

```json
{
    "ok": true,
    "problem": null,
    "candidate": "034cfb84fe1e644ae8254fdb3b7dd2b47d672fe6",
    "dirty": false,
    "docs_only": false,
    "gate": "quality-check",
    "artifact": "/home/nckrtl/orbit/.worktrees/codex-fix-macos-desktop-update-selection/.orbit/quality-gates/quality-check-2026-08-23T223248Z-d4fa8fce40b8.json",
    "venue": "retained-incus",
    "runtime": "passed - candidate=034cfb84fe1e644ae8254fdb3b7dd2b47d672fe6; venue=retained-incus; environment=dev-fixture; target=retained Incus topology dev-b77c00 exact-candidate workload fan-out; expected=every active supported non-gateway node is selected regardless of roles or managed, reachable Linux nodes complete CLI and Agent installation, and nodes unreachable before mutation skip without aborting the remaining fan-out; observed=five managed=false targets were selected, app-dev-1 and app-prod-1 completed with verified 0.1.196 CLI and Agent identities, agent-1 and offline-linux skipped with orbit_agent_not_running, offline-mac skipped with orbit_desktop_not_running, and the exact renderer PTY frame showed both platform-specific reasons; result=passed; evidence=`.orbit/evidence/retained-dev-b77c00/runtime-proof.md`"
}
```

Did not patch harness files. Did not merge.
