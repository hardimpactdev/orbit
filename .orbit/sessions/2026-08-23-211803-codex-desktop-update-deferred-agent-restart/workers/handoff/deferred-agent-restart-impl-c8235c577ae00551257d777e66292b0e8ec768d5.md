candidate=c8235c577ae00551257d777e66292b0e8ec768d5

Desktop-managed fleet-update CLI installs now skip standalone Agent restart when both `desktop_artifact` and `pending_desktop_update` are present.

## Behavior

- `LocalFleetUpdateInstallCliEnvironment` sets `ORBIT_DEFER_AGENT_RESTART_TO_DESKTOP=1` only when both payloads exist.
- The install script emits `defer_agent_restart_to_desktop` and skips `restart_agent_service_if_present` for that flag only.
- CLI and Agent bytes still download, install, and verify. `stageDesktopUpdate()` still writes the owner-only handoff.
- systemd, legacy launchd, and unmanaged restart/fail-closed paths are unchanged when the flag is absent.

## Proof

- Candidate: `c8235c577ae00551257d777e66292b0e8ec768d5` (clean HEAD, no code changes after commit).
- Focused Pest: `InternalFleetUpdateInstallCliCommandTest` 25 passed, isolated from host launchd/systemd.
- Focused Mago lint on `LocalFleetUpdateInstallCliEnvironment.php`: no issues.
- `composer quality-check`: exit 0. Artifact `.orbit/quality-gates/quality-check-2026-08-23T182650Z-1f9afc4127d2.json`.
- `bin/orbit-feature-acceptance route` venue: `retained-incus`.
- `bin/orbit-feature-proof-receipt --loop=.orbit/loop.md`: `ok=true`, dirty=false, gate=`quality-check`, runtime passed with evidence `.orbit/evidence/retained-incus-proof.md`.
