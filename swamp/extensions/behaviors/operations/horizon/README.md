# Horizon handoff

The release runner still admits one `process.horizon_handoff` Operation with its public rendered unit and pinned Node SSH target. The workflow now uses official cfgmgmt systemd checks and apply methods. A native assertion accepts the terminal state. No script or staging directory remains.

An unchanged, enabled unit skips its write. A changed unit is written, reloaded and enabled without stopping it. Active Horizon receives one bounded `timeout 60` SSH argv command for `horizon:terminate`; success means graceful termination was accepted, so the Gateway reports `terminated` with state `unknown`. Inactive Horizon is started and checked active, then reports `created` or `started`. A failed action retains its effects and a rerun converges. Runtime ownership checks and pre-publication staging are dropped by rule 22.

Run `SWAMP_BIN=/path/to/pinned/swamp python3 -m unittest discover -s swamp/extensions/behaviors/operations/horizon -p native_test.py -v` for the five native cases. They cover both branches, changed active units, refusal at enable and failed graceful termination with reruns. The fixture reuses the Process host and official extension setup.

The minimal proof fixtures exercise the production Gateway admission, actual systemd, SSH and terminal projection. Their PHP process fixture implements the Horizon CLI boundary without Redis or a job engine; it proves dispatch and graceful acceptance, not job draining or application behavior.
