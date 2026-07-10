# Capture-evidence integrity retained topology proof

- Feature commit: `b6832b747ab568942b234c84c6ca29e5e2926430`
- Acquisition command: `composer e2e:incus -- --start --topology=operator --checkout-roles=operator --json`
- Retained topology: id `dev-088547`; kind `operator`; provider/host `incus` / `beast`; checkout role `operator`; instance `orbit-e2e-dev-088547-operator`.
- Runtime source mirror: `/home/orbit/orbit-run`.
- Inspection session: Solo terminal process `992` (`capture-integrity-topology-proof`), attached with `ssh -tt beast "incus exec orbit-e2e-dev-088547-operator -- sudo -iu orbit bash -lc 'cd /home/orbit/orbit-run && exec bash -i'"`.
- Source identity proof: runtime SHA-256 values matched the local exact-commit worktree:
  - `bin/orbit-agent-session-capture`: `8c6ff08a098e462045ab1cd3110bd0a808a98a4f783e44aaebbd08f59a3e3d2f`
  - `bin/orbit-agent-session-capture-filesystem.php`: `e99b5195c92111ec345ce64ee1363c77b38e10a8b7db5684621599866bd24d45`
- Runtime syntax commands: `php -l bin/orbit-agent-session-capture` and `php -l bin/orbit-agent-session-capture-filesystem.php`; both reported no syntax errors.
- Behavior command: `bin/orbit-agent-session-capture 1 --provider=claude --solo-db=/tmp/capture-topology-proof.db --orbit-dir=/tmp/capture-topology-proof-orbit --incarnation-started-at=2026-07-10T00:00:00Z --slug=topology-proof-capture`, using an owned temporary SQLite process-row fixture.
- Observed result: stderr `incarnation_floor_unsupported_provider`; exit `2`; `/tmp/capture-topology-proof-orbit` remained absent, proving the unsupported floor failed before staging on the retained source-mounted VM.
- Exploratory pre-fixture invocation: reached the expected missing-Solo-DB precondition and was not counted as the behavior proof; it also created no staging state.
- Release command: `composer e2e:incus -- --stop --id=dev-088547 --json`; released instance `orbit-e2e-dev-088547-operator` with exit `0`.
- Cleanup proof: `ssh beast "incus list --format csv -c ns | grep dev-088547 || true"`; exit `0`, empty output.
- Result: `passed`.
