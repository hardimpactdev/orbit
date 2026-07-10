# Signal: CLI Pest Inherited Operator TTY Stdin

Status: guarded
First seen: 2026-07-10
Last seen: 2026-07-10
Last reviewed: 2026-07-10
Source worktree: agent-session-capture-disambiguation
Source commit: ded3b388a502e549b62b1e7059ebbc06f5f2ac91
Signal type: setup
Guardrail target: bin/orbit-cli-pest; apps/gateway/tests/Feature/E2ESupport/VerificationScriptsTest.php
Guardrail change: cli-pest-noninteractive-baseline
Related signals: harness-signals/2026-06-24-cli-pest-parallel-bootstrap-blocker.md
Superseded by: none
Tags: cli, pest, tty, stdin, worktree, preparation, determinism

## Signal

Two clean `bin/orbit-prepare-worktree` runs reached serial CLI Pest and then
waited on the operator terminal instead of completing. Sending Ctrl-D resumed
the suite, and native samples showed PHP blocked in `stream_select` and later
`stream_get_contents` reads from `/dev/tty`. The preparation loop could neither
pass nor surface a useful failure without human input.

The retained source evidence is
`.orbit/sessions/2026-07-10-021736-agent-session-capture-disambiguation/evidence/cli-pest-tty-stdin-preparation-blocker.md`.

## Prior Occurrences

No matching serial-stdin signal record existed. The related June 24 record is
about ParaTest bootstrap and nested-runner contention; it does not cover a
serial launcher inheriting file descriptor 0 from an operator TTY.

## Missing Guardrail

`bin/orbit-cli-pest` forwarded caller stdin to Pest. Any current or future test
could therefore consume terminal input and turn worktree preparation or a
direct serial test run into an indefinite wait. Existing split/background
quality-check execution happened to provide non-TTY stdin, but direct callers
had no deterministic boundary.

## Guardrail Change

`bin/orbit-cli-pest` now attaches only the child stdin to `/dev/null`. A
functional gateway regression launches the wrapper with an open unread Symfony
`InputStream` and a fake `php`, then proves EOF, the `apps/cli` working
directory, forwarded arguments, and child exit status. Stdout and stderr remain
untouched.

## Verification

The focused regression timed out before the launcher change and passed after
it. The complete non-TTY CLI suite passed 2,175 tests with 9,111 assertions.
An unattended PTY `composer test` run completed in 81.493 seconds with no
timeout or input; its non-zero result exposed a separate stdout-TTY capability
defect instead of the former hang. `composer quality-check` then passed every
subgate in 102 seconds.

The retained red/green and PTY matrix is summarized in
`.orbit/sessions/2026-07-10-030001-cli-pest-noninteractive-baseline/evidence/cli-pest-noninteractive-baseline.md`.

## Reappearance Check

If serial CLI Pest or worktree preparation waits for terminal input again,
first run the focused `prevents CLI Pest from consuming caller stdin`
regression and inspect `bin/orbit-cli-pest` for lost stdin detachment. Capture
the exact caller under a PTY without supplying input. A run that completes but
fails renderer assertions is not this signal; route stdout/output-capability
failures to the owning renderer or core contract.

## Curation Notes

Keep this record separate from the parallel-bootstrap signal. Both mention CLI
Pest, but their triage questions, guardrail targets, and reappearance checks are
different: this record owns serial stdin determinism; the June 24 record owns
parallel bootstrap and runner contention.
