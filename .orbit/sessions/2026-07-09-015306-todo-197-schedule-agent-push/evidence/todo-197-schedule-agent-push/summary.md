# TODO 197 Schedule Agent-Push Evidence

- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-mig04-schedule-agent-push`
- Branch: `codex/mig04-schedule-agent-push`
- Commit: `b891f666314ed9a1ec2065c2ce548be1a71c6d85`
- Origin refs: `origin/main` and `origin/codex/mig04-schedule-agent-push` both at `b891f666314ed9a1ec2065c2ce548be1a71c6d85`
- RC build id: `20260708T232933Z-b891f6663`
- Candidate manifest: `https://s3.hardimpact.dev/orbit/channels/live-test/orbit-release-manifest.json`
- Candidate gateway image: `ghcr.io/hardimpactdev/orbit-gateway:0.1.180-candidate-20260708T232933Z-b891f6663`
- Gateway digest: `sha256:7eb912f299e5fcea959abae02663df7c40f0e25d1ebd6f33254154b59d78a9ca`
- Reverb candidate image: `ghcr.io/hardimpactdev/orbit-reverb:0.1.180-candidate-20260708T232933Z-b891f6663`
- Reverb digest: `sha256:e561db2e5eede61c9fe08fd4e8106048035b44545cd8cc8413dbc13bc6f3c13f`

## Focused Verification

- `bin/orbit-cli-pest --compact tests/Feature/InternalWorkspaceSetupStepCommandTest.php tests/Feature/InternalScheduleRunCommandTest.php`
  - Passed: 7 tests, 19 assertions.
- `bin/orbit-gateway-pest --compact tests/Feature/Commands/Schedule/OrbitSchedulerCommandTest.php tests/Unit/Services/RemoteShell/LocalExecutorCommandBuilderTest.php tests/Unit/Services/Schedules/SchedulesProbeTest.php`
  - Passed: 125 tests, 701 assertions.
- `composer docs-lint`
  - Passed with existing warnings only.
- CLI scoped `mago:lint`, `mago:format:check`, and `mago:analyze`
  - Passed; analyze reported warning-only baseline noise.
- Gateway scoped `mago lint` and `mago format --check`
  - Passed; lint reported the existing helper-class filename warning in `OrbitSchedulerCommandTest.php`.
- `composer quality-check`
  - Passed. Key lanes: gateway Pest 4355 passed; CLI Pest 2132 passed; docs Pest 128 passed; core Pest 111 passed; SDK Pest 128 passed; docs-lint passed.

## RC Verification

- `bin/orbit-release-candidate build`
  - Passed and published live-test candidate `20260708T232933Z-b891f6663`.
- `bin/orbit-release-candidate verify`
  - Passed local candidate artifact hash checks:
    - `sha256_linux_amd64=3d571b8e3eea41b558ce8f7823666892a1c3eedb470f8d3f2cb0bf044e6dca46`
    - `sha256_darwin_arm64=bf4f08248ee52ca99f4d1a45fbc8de2bf7358adac3a01af7d9ed93d963b0365d`
    - `sha256_agent_linux_amd64=0d3c4b577550eadc5f44dff4190a8cb3796fbe3281e074cf1a380c25b1640312`
    - `sha256_agent_darwin_arm64=cb3219f0db3647ab9e8725e956b3c7eb084edaa742158ad9ac6fb278c7701bdd`

## Live Topology Verification

- `./apps/cli/orbit manifest:update https://s3.hardimpact.dev/orbit/channels/live-test/orbit-release-manifest.json --json`
  - Passed; gateway manifest source set to custom live-test channel.
- First `ORBIT_RELEASE_MANIFEST_URL=... ./apps/cli/orbit update:all --stream-json`
  - Installed gateway, scheduler, and workload artifacts, then failed final verification on `mini` agent reachability.
  - Follow-up doctors showed unrelated live drift on `mini` and `NMBP`; the failed update was rerun after services settled.
- Second `ORBIT_RELEASE_MANIFEST_URL=... ./apps/cli/orbit update:all --stream-json`
  - Passed.
  - Complete frame: `status=succeeded`, `manifest_source=topology-candidate`, `target_version=0.1.180`, `manifest_version=0.1.180`.
  - Candidate URLs referenced build id `20260708T232933Z-b891f6663` for CLI and agent artifacts.
- Final gateway status:
  - `./apps/cli/orbit gateway:status --json` returned version `0.1.180` at `2026-07-08T23:44:08Z`.
- Final operator binary:
  - `/Users/nckrtl/.local/bin/orbit --version` returned version `0.1.180`, released `09-07-2026 - 01:29`, installed `09-07-2026 - 01:42`.

## Schedule Smoke

- Target node: `NMBP` (`app-dev`, non-gateway).
- `./apps/cli/orbit schedule:add todo-197-final-b891f666 --node=NMBP --command='printf todo197-final-b891f666' --interval='daily at 09:00' --json`
  - Passed; `scheduler_pickup=confirmed`.
- `./apps/cli/orbit schedule:run todo-197-final-b891f666 --node=NMBP --json`
  - Passed.
  - Run id `8`, status `completed`, exit code `0`, stdout `todo197-final-b891f666`, stderr empty.
- `./apps/cli/orbit schedule:remove todo-197-final-b891f666 --node=NMBP --force --json`
  - Passed; `scheduler_pickup=confirmed`, history retained.
- `./apps/cli/orbit schedule:list --node=NMBP --json`
  - Passed; returned `count=0`.
- `./apps/cli/orbit activity:list --node=NMBP --include-internal --limit=12 --json`
  - Showed final internal dispatch/completion rows:
    - `146102`: `local_executor.dispatching`, command `internal:schedule:run`, subject node `NMBP`.
    - `146103`: `local_executor.completed`, command `internal:schedule:run`, subject node `NMBP`.
- `./apps/cli/orbit activity:show 146102 --json`
  - Dispatch command line: `'/Users/nckrtl/.local/bin/orbit' internal:schedule:run --operation-token=<redacted> --json`.
- `./apps/cli/orbit activity:show 146103 --json`
  - Completed with `exit_code=0`, stdout summary containing `todo197-final-b891f666`, stderr empty, duration `927ms`.

## Solo Review

- Solo Claude process: `901` (`claude-schedule-agent-push-review`).
- Verdict: `ACCEPT`.
- Concern reviewed: sequential agent-push dispatch versus previous SSH-pool batching.
- Follow-up recommended: add an audited async internal-command pool/start API before long-running remote schedules become common.
