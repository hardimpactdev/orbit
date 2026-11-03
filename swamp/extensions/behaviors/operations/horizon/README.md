# Horizon handoff

The release runner still admits one `process.horizon_handoff` Operation with its public rendered unit and pinned Node SSH target. The workflow now uses official cfgmgmt systemd checks and apply methods. A native assertion accepts the terminal state. No script or staging directory remains.

Native apply leaves an unchanged, enabled unit untouched. A changed unit is written, reloaded and enabled without stopping it. Active Horizon receives one bounded `timeout 60` SSH argv command for `horizon:terminate`; the workflow then checks the enabled unit is active. The Gateway reports `terminated` with state `active`. Inactive Horizon is started and checked active, then reports `created` or `started`. A failed action retains its effects and a rerun converges. Runtime ownership checks and pre-publication staging are dropped by rule 22.

Run `deno test -A swamp/extensions/behaviors/operations/horizon/handoff_native_test.ts` for the five native cases. They cover both branches, changed active units, refusal at enable and failed graceful termination with reruns. The fixture reuses the Process host and official extension setup from `process-commands/native_fixture.ts`.

The minimal proof fixtures exercise the production Gateway admission, actual systemd, SSH and terminal projection. Their PHP process fixture implements the Horizon CLI boundary without Redis or a job engine; it proves dispatch and graceful acceptance, not job draining or application behavior.
