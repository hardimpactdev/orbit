# CLI Pest non-interactive baseline evidence

## Contract

`bin/orbit-cli-pest` must never consume the caller's stdin. It must preserve
the CLI application working directory, Pest arguments, child exit status,
stdout, and stderr.

## Failing-first proof

- Worker: Solo process `953` (`grok`).
- Functional test: `VerificationScriptsTest.php` starts the launcher with an
  open `Symfony\Component\Process\InputStream` and a fake `php` executable.
- Before the launcher change, the fake child waited for input and the 2-second
  process timeout failed the test.
- PTY capture: `orbit-cli-pest-stdin-before/`.
- Result: `composer test` exited `2` in `15.824s`; the new gateway regression
  failed by timeout before the aggregate reached the CLI lane.
- The originating operator-TTY hang remains retained in
  `.orbit/sessions/2026-07-10-021736-agent-session-capture-disambiguation/`.

## Green proof

- Focused gateway regression:
  `bin/orbit-gateway-pest --compact tests/Feature/E2ESupport/VerificationScriptsTest.php --filter='prevents CLI Pest from consuming caller stdin'`
  passed: 1 test, 6 assertions, 325ms.
- Full non-TTY CLI suite: `bin/orbit-cli-pest --compact` passed: 2,175 tests,
  9,111 assertions, 73.90s.
- Exact PTY aggregate: `orbit-cli-pest-stdin-after-full/` completed without
  input, timeout, or idle timeout in 81.493s. It exited `1` with 21
  renderer-related failures rather than hanging.

## PTY diagnostic matrix

All captures target only
`tests/Feature/Services/Updates/UpdateAllHumanProgressRendererTest.php`.

| Capture | Result |
| --- | --- |
| `orbit-renderer-pty-direct/` | exit 1; 9 failed, 10 passed |
| `orbit-renderer-pty-piped/` | exit 0; 19 passed, 76 assertions |
| `orbit-renderer-pty-ci/` | exit 1; 9 failed, 10 passed |
| `orbit-renderer-pty-no-color/` | exit 1; 9 failed, 10 passed |
| `orbit-renderer-pty-term-dumb/` | exit 1; 9 failed, 10 passed |

Only piping Pest stdout changed the result. `CI=1`, `NO_COLOR=1`, and
`TERM=dumb` did not. Source inspection identified the independent cause:
`LiveRepaintOutput::resolveStream()` falls back to global `STDOUT` for an
injected non-stream output, so `BufferedOutput(decorated: false)` inherits the
host process's TTY capability.

Claude process `solo://proj/4/process/claude-code--943` adjudicated the stdin
repair as complete inside its original two-file boundary and classified the
PTY result as a separate `packages/core` contract defect. That repair must land
before the blocked capture-disambiguation worktree is prepared again.

