candidate=f9a9f40793167ea32f6b202bef8d32cccd5fbd18

Narrow DEFECT 4 correction. Did not merge. Did not run or delegate `composer test:e2e*`. Did not edit owner `.orbit` loop or evidence.

## Candidate

- SHA: `f9a9f40793167ea32f6b202bef8d32cccd5fbd18`
- Subject: `Exclude gateway nodes from fleet-update eligibility`
- Parent: `7f8ba5b6da0c954bbb480ef6143544513ec420a0`
- Worktree dirty=false

## Correction

- `Node::isFleetUpdateEligible()` now returns false when the node has an active `gateway` role.
- `FleetVersionProbeTest` adds an Ubuntu `ubuntu_24-04` gateway with matching image and CLI plus `agent_artifacts['linux-amd64']`, asserting `outdatedCount === 0` and `allCurrent() === true`.
- `NodeAgentEligibilityTest` asserts a Ubuntu gateway is not fleet-update eligible while a roleless unmanaged Ubuntu node remains eligible.

Ordinary `isAgentEligible()` / Agent-intent paths are unchanged.

## Focused proof

- `tests/Unit/Services/Nodes/NodeAgentEligibilityTest.php` — 7 passed (watched the new case fail, then pass)
- `tests/Feature/Services/Operations/FleetVersionProbeTest.php` — 15 passed (watched the Ubuntu-gateway all-current case fail, then pass)
- `tests/Unit/Services/Operations/FleetUpdateTargetSelectorTest.php` — 2 passed
- Focused Mago format/lint/analyze on `Node.php` and the two test files

## Quality-check

`composer quality-check` exit 0 on clean HEAD `f9a9f40793167ea32f6b202bef8d32cccd5fbd18`.

- artifact: `.orbit/quality-gates/quality-check-2026-08-24T001837Z-6c30635284e3.json`
- git.commit=`f9a9f40793167ea32f6b202bef8d32cccd5fbd18`
- dirty=false
- duration_seconds=138
- Did not run `composer test:e2e*`

## Proof receipt

Unmodified `bin/orbit-feature-proof-receipt --json` on clean HEAD:

```json
{
    "ok": true,
    "problem": null,
    "candidate": "f9a9f40793167ea32f6b202bef8d32cccd5fbd18",
    "dirty": false,
    "docs_only": false,
    "gate": "quality-check",
    "artifact": "/home/nckrtl/orbit/.worktrees/codex-fix-macos-desktop-update-selection/.orbit/quality-gates/quality-check-2026-08-24T001837Z-6c30635284e3.json",
    "venue": "retained-incus",
    "runtime": "passed - candidate=f9a9f40793167ea32f6b202bef8d32cccd5fbd18; venue=retained-incus; environment=dev-fixture plus NMBP macos-27-arm64; target=reachable roleless unmanaged Linux node with current CLI and missing Agent plus native NMBP Desktop staging plus current Ubuntu gateway boundary; expected=the Linux node installs and verifies its missing Agent regardless of roles or management state, the Mac stages verified Desktop plus Agent plus CLI with restart-ready handoff, unreachable nodes skip before mutation, and a current Ubuntu gateway stays outside workload Agent drift; observed=app-dev-1 was unmanaged with no roles, a current CLI, and no Agent identity, then completed, passed Agent restart readiness and Agent verification, recorded the expected Agent hash, and became current, while NMBP installed isolated CLI and Agent artifacts, staged the Desktop archive, wrote a 0600 restart-ready handoff owned by Desktop, and kept the existing Agent PID unchanged, offline Linux and Mac retained their platform-specific skip results, and the Ubuntu gateway regression reported zero outdated nodes and all current; result=passed; evidence=`.orbit/evidence/retained-dev-b77c00/runtime-proof.md`"
}
```

Owner-owned runtime is rebound on this SHA. The Ubuntu gateway all-current branch reported zero outdated nodes.

Did not merge.
