---
title: "Development auto-deploy cutover"
description: "Move development decisions to the Gateway while preserving production policy and the disabled release gate."
---

# Development auto-deploy cutover

Development auto-deploy remains off. This plan authorizes no live change. The normal [Instance switch](/cli/instance/auto-deploy-enable) controls ready container defaults. Production remains owned by Swamp.

## Before a release

The release owner keeps admission disabled until these gates pass.

1. Report the pinned SHA, selected Instance list and each `auto_deploy` state. Report the production schedule pause and absence of a development schedule. Unknown state or an enabled development default stops the release.
2. Prove all 208 reference Deno cells through the explicit mapping in `apps/gateway/tests/Fixtures/development-deploy-matrix.json`. Missing mappings fail. Record hashes of unchanged production sections.
3. Export all nine `development-<id>` checkpoints with complete data, IDs, versions, a manifest and checksums. Keep them in a protected incident hold.
4. Verify every export and report a missing or additional checkpoint.
5. After separate authorization, delete only the verified checkpoints through supported Swamp operations and require zero remaining development checkpoints.

Keep production resources unchanged and its native schedule paused. Containers and stock-image work keep priority. Do not deploy the discarded development workflow or start a Swamp development tick.

## Future proof, only after authorization

Release the reviewed Gateway code with every development switch still off. Confirm that an off default admits nothing and that production stays paused. On one authorized disposable Project, enable only its ready container default, push a green default-branch commit, and follow the ordinary instance.deploy Operation through seed projection. Measure push-to-seed time and ten quiet ticks; unchanged defaults must start no Swamp run.

A checkout and a clone must remain unchanged for five minutes. Verify red and pending checks do not deploy, disable stops new admissions, and disable/enable preserves retry attempts. Restore the authorized switch state and preserve evidence. Retire the incident hold only after the release owner accepts the proof.

## Why it works this way

The Gateway already owns the switch, source evidence, Operation admission and durable deployment records. Keeping the development decision there removes a policy run that called back for the same state. Swamp continues to execute the ordinary deployment. Retry rows for each SHA survive the 50-row display-history limit; no second retry store or policy writer is added.
