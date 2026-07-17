# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: none
- Worktree: `/Users/nckrtl/.codex/worktrees/services1-analytics-s3`
- Branch: `codex/services1-analytics-s3`

## Goal

Provision `services1` at `46.224.166.1` with active `analytics` and `s3`
roles, backed by PostgreSQL and ClickHouse processes on `database1`, using the
official Plausible CE 3.2.1 database image pairing.

## Scope

- Owned:
  - `apps/cli/app/Services/Node/NodeBootstrapSshRunner.php`
  - `apps/cli/app/Services/Processes/LocalDockerContainerAction.php`
  - `apps/cli/tests/Feature/Commands/Node/NodeNewBootstrapCommandTest.php`
  - `apps/cli/tests/Feature/InternalProcessDockerContainerCommandTest.php`
  - `apps/gateway/app/Services/Nodes/NodeCreationRoleResolver.php`
  - `apps/gateway/app/Services/Nodes/GatewayNodeCreator.php`
  - `apps/gateway/app/Services/Nodes/Roles/NodeRoleAssignmentService.php`
  - `apps/gateway/app/Models/Node.php`
  - `apps/gateway/app/Services/RemoteShell/LocalExecutorCommandBuilder.php`
  - `apps/gateway/app/Services/Processes/ProcessServiceCatalog.php`
  - `apps/gateway/app/Services/Analytics/AnalyticsProcessEndpointResolver.php`
  - `apps/gateway/app/Services/Analytics/PlausibleRuntimeConfig.php`
  - `apps/gateway/app/Services/Nodes/Roles/RoleBaselines/AnalyticsRoleBaseline.php`
  - `apps/gateway/tests/Unit/Services/Nodes/NodeRoleAssignmentServiceTest.php`
  - `apps/gateway/tests/Unit/Services/Nodes/NodeAgentEligibilityTest.php`
  - `apps/gateway/tests/Unit/Services/RemoteShell/LocalExecutorCommandBuilderTest.php`
  - focused CLI and gateway tests for those contracts
  - node-new, analytics/process, and node-role-remove product documentation
  - live nodes `database1` and `services1`
- Constraints:
  - preserve client-owned SSH bootstrap and strict host-key verification
  - use Plausible CE 3.2.1 with `postgres:16-alpine` and
    `clickhouse/clickhouse-server:24.12-alpine`
  - do not expose generated credentials or secret material in output/evidence
  - do not invoke manual Composer E2E lanes
- Out of scope:
  - websocket role implementation
  - public DNS and ingress routing for the analytics UI
  - unrelated managed-service credential redesign

## Proof

- Verification:
  - focused: passed - internal-command authorization and service-role lifecycle slice 183 tests/869 assertions; Agent eligibility/transport slice 16 tests/87 assertions; gateway role-removal suite 67 tests/186 assertions and broader removal slice 85 tests/277 assertions; CLI protected bind-source and container slice 22 tests/89 assertions; retained-topology readiness 10 tests/63 assertions; gateway role, process, node-new, and role-add slice 282 tests/1681 assertions; CLI managed-file, Docker container, and Swarm slice 30 tests/88 assertions; docs lint, scoped Mago format/lint/analyze, Rector, and diff checks passed
  - broader: passed - serialized `ORBIT_QUALITY_CHECK_CPU_BUDGET=1 composer quality-check` completed with exit code 0 across gateway, CLI, docs, E2E-static, Reverb, Rust, core, and SDK; artifact `.orbit/quality-gates/quality-check-2026-07-17T045947Z-71e6250b1f75.json`
  - runtime: passed - exact-head retained Incus topology `dev-31c2f9` made `app-prod-1` roleless, added analytics before S3, removed analytics and then S3 as the last role, preserved PostgreSQL/ClickHouse, enforced WireGuard-only binds and root-owned 0600 S3 configuration, and was released; proof `.orbit/evidence/runtime-proof.txt`
- Blast radius: complete - evidence=full base-to-tip review plus bounded searches across node bootstrap, process credentials and payloads, Docker and Swarm runtimes, role removal transactions, analytics endpoints, S3 managed files and path validation, migrations, tests, product docs, and exact-head retained Incus runtime; result=all known review and runtime findings are closed with no untested owned surface remaining
- Review: passed - human-judgment=not-required; independent exact-tip review found no remaining findings and confirmed the unmasked role lifecycle proof
- Reviewed feature tip: 0c61ba11be9ad42fec4aedbea4ff15d43ea4999f
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 0c61ba11be9ad42fec4aedbea4ff15d43ea4999f
- Accepted main tip: 7b7b5fd27d6e9957ee201519ee6afc9e678cd6ae

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`

Allowed states are `frame`, `build`, `prove`, `accept`, `accepted`, `land`, and
`blocked`. Review must be `passed`, `fix`, or `escalate`. Acceptance must be
`pending`, `accepted`, `not-applicable`, or `changes-requested`. A terminal
review PASS must record `human-judgment=required|not-required` and the exact
committed HEAD in `Reviewed feature tip`. Blast radius must record either why
it is not required or the complete evidence and result before acceptance;
`gaps` returns to BUILD.
Proof files retained by the compact archive are cited as exact inline-code
paths rather than directories or partial paths.
