# Retained Incus activity-contract proof

- Candidate: `aa2b2cf68c22d6cc9cf9016e81d82f11baee7138`
- Venue: `retained-incus`
- Environment: `dev-fixture`
- Topology: `dev-636f94` (`operator_gateway` on `beast`)
- Solo terminal: `activity-retained-proof-aa2b2cf` (process 2552, project 109)
- Runtime checkout: `/home/orbit/orbit-run`
- Launcher: `/home/orbit/orbit-run/apps/cli/orbit`
- Local and runtime launcher SHA-256: `eb19bf3561cf7627029de7e9b105b1460b72dacbf0511adb9004727c4fd4068a`

## Commands and observations

The commands ran inside the retained operator VM from `/home/orbit/orbit-run`.

1. `./apps/cli/orbit extension:enable cloudflare --node=gateway --json`
   succeeded. The next `activity:list --json` entry had type
   `api:POST /extensions/{extension}/enable`, effect `write`, subject
   `{type: gateway_extension, name: 1}`, actor `operator-1`, and only the
   declared extension plus request metadata.
2. `./apps/cli/orbit extension:disable cloudflare --node=gateway --json`
   succeeded. The next activity entry had type
   `api:POST /extensions/{extension}/disable`, effect `write`, and subject
   `{type: gateway_extension, name: 1}`.
3. `curl -sS -X POST -H 'Accept: application/json' http://10.6.0.2/api/extensions/missing/enable`
   returned `extension_unknown`. The next activity entry retained the exact
   enable type and `write` effect, had `subject: null`, and exposed only
   `extension: missing` plus request metadata.
4. The runtime checkout contained the candidate's 2026-08-20 activity-emission
   decision. The launcher returned valid local version JSON.
5. After the final candidate sync, the runtime checkout contained the corrected
   `deploy:run` contract: the accepted response has no `DeploymentRun` subject
   because deferred execution creates it later. A repeated gateway extension
   enable still emitted the expected `gateway_extension` subject.
6. After the integration-gate repair sync, the runtime checkout contained the
   canonical Solo `project/list` activity operation. A gateway extension
   disable emitted the expected `gateway_extension` subject.

Result: passed. The retained topology exercised successful and failed gateway
activity emission through the candidate source-mounted CLI and gateway.
