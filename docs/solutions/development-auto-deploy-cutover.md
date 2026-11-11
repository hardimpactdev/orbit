---
title: "Development auto-deploy cutover"
description: "Move development decisions to the Gateway while preserving production policy and the disabled release gate."
---

# Development auto-deploy cutover

Development auto-deploy remains off. This plan authorizes no live change. The normal [Instance switch](/cli/instance/auto-deploy-enable) controls ready container defaults. Production remains owned by Swamp.

## Before a release

1. Report the pinned release SHA, complete selected Instance list and every selected Instance's `auto_deploy` state to the release owner. Report the production native schedule's persisted pause and the absence of a development native schedule. Stop on an unknown state or an enabled development default. Keep Containers and stock-image work ahead of this release.
2. Prove all 208 registered cells from closed reference #3359 (`e635db82`) through the explicit Deno-cell to Pest table in `apps/gateway/tests/Fixtures/development-deploy-matrix.json`. A missing cell or a label without behavioral assertions fails the gate. Record hashes proving production executable sections did not change.
3. Keep automatic development admission disabled while exporting all nine retained `development-<id>` checkpoints. Preserve their full native data and metadata in a protected incident hold, with IDs, versions, manifest and checksums. Export through the supported Swamp data API or CLI; verify each export before deleting anything. Report a missing or additional checkpoint to the release owner.
4. After separate authorization, delete only those verified development checkpoints through supported Swamp data operations. List again and require zero development checkpoints. Keep production `state-*`, `fire` resources and the native `automatic-production-deploy` schedule unchanged and paused. Do not deploy the discarded development workflow or start a Swamp development tick.

## Future proof, only after authorization

Release the reviewed Gateway code with every development switch still off. Confirm that an off default admits nothing and that production stays paused. On one authorized disposable Project, enable only its ready container default, push a green default-branch commit, and follow the ordinary instance.deploy Operation through seed projection. Measure push-to-seed time and ten quiet ticks; unchanged defaults must start no Swamp run. A checkout and a clone must remain unchanged for five minutes. Verify red and pending checks do not deploy, disable stops new admissions, and disable/enable preserves retry attempts. Restore the authorized switch state and preserve evidence. Retire the incident hold only after the release owner accepts the proof.

## Why it works this way

The Gateway already owns the switch, source evidence, Operation admission and durable deployment records. Keeping the development decision there removes a policy run that called back for the same state. Swamp continues to execute the ordinary deployment. Per-SHA retry rows survive the 50-row display-history limit; no second retry store or policy writer is added.
