# CLI Pest TTY stdin preparation blocker

Date: 2026-07-10 CEST
Source checkout: `/Users/nckrtl/orbit/.worktrees/agent-session-capture-disambiguation`
Source commit: `ded3b388a502e549b62b1e7059ebbc06f5f2ac91`

## Attempt 1

- Command: `bin/orbit-prepare-worktree agent-session-capture-disambiguation`
- Prepare PID: 39070; Composer PID: 41921; CLI Pest PID: 48544.
- CLI Pest launch: 01:46:05 CEST; interrupted after 12m23s.
- State: no child processes; approximately 4% CPU; no network socket or pipe file descriptors.
- `lsof`: fd0 `/dev/tty` read; fd1/fd2 `/dev/tty`; fd6 zero-byte PHP temp file.
- Native samples at 01:56:37 and 01:58:23 showed 787/796 and 440/448 frames in `zif_stream_select -> __select`.
- Intervention: Ctrl-C to the PTY process group; exit 1. Worktree remained clean.

## Attempt 2

- Command: the same sanctioned prepare command, unchanged.
- Gateway baseline: passed 4400 tests / 25275 assertions in 14.798s.
- CLI Pest PID: 55881; progress dots emitted before another no-child wait.
- Native sample at 02:01:17 showed 89/90 frames in `zif_stream_select -> __select`; fd0-2 again pointed to `/dev/tty`.
- Claude process 943 advised one bounded Ctrl-D probe. The exact EOF byte immediately resumed hundreds of tests and exposed failures, proving the CLI test process was consuming operator stdin.
- A later wait at 02:05:02 sampled 86/86 frames in `zif_stream_get_contents -> _php_stream_read -> php_stdiop_read -> read` on the controlling terminal. One EOF ended one read only; the next stdin-consuming test blocked again.
- Intervention: Ctrl-C; exit 1. The assisted run is not a passing preparation baseline.

## Adjudication

- Claude first advised one unchanged rerun, then escalation to blocked if the signature reproduced.
- Claude approved launcher-level `/dev/null` stdin as the smallest guardrail and approved a separate worktree prepared with the sanctioned `--skip-tests` option because the skipped baseline is the behavior under repair.
- Final orchestrator decision: archive this slice as blocked with no tracked diff, implement `cli-pest-noninteractive-baseline` independently, then reprepare capture disambiguation from updated main.
- Durable roadmap record: `solo://proj/4/scratchpad/orbit-feature-loop-r--276`, revisions 14-20.

The macOS `sample` reports were generated under `/tmp` for diagnosis. The stable evidence needed for triage is distilled above; raw disposable reports are not treated as repository artifacts.
