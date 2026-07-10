CHECKOUT_PROOF: /Users/nckrtl/orbit | main | 1f08ce59f9b8b4df8605dfdcd2cf15245d26303d | M .orbit/sessions/index.json; 20 untracked .orbit session archives; untracked docs/superpowers/plans/2026-07-08-instance-runtime-mounts.md

## Findings

- P2 — `packages/core/src/Progress/LiveRepaintOutput.php:16`: an explicitly undecorated output attached to a TTY is still promoted to live/styled output before `OutputInterface::isDecorated()` is consulted. This is a real capability-dispatch defect, not a speculative risk. `StreamedStepTree` consumes that result as its `$styled` flag at `packages/core/src/Progress/StreamedStepTree.php:70`, and `UpdateAllHumanProgressRenderer` does the same at `apps/cli/app/Services/Updates/UpdateAllHumanProgressRenderer.php:957`, so callers that deliberately disable decoration can still receive color, cursor hiding, cursor movement, and erase sequences. Reproduction on current main used a real PTY with `ConsoleOutput`, called `setDecorated(false)`, then rendered a `StreamedStepTree`: `/tmp/program-review-consoleoutput-no-ansi/summary.txt` records exit 0, while the transcript begins `decorated=false tty=true supports=true` and contains 52 ANSI CSI sequences. The merged regression at `packages/core/tests/Progress/LiveRepaintOutputTest.php:38` covers only non-stream `BufferedOutput(false)`; the retained topology probe likewise covered only default decorated `ConsoleOutput`, so neither exercises this TTY + explicitly-undecorated quadrant. Separate repaint capability from decoration permission, and add a 2x2 regression matrix for stream/non-stream and decorated/undecorated outputs under TTY/non-TTY conditions.

No P0 or P1 findings. The P2 requires changes under the requested verdict policy.

## Non-findings and residual risks

- `bin/orbit-cli-pest:10` correctly redirects only child stdin to `/dev/null`; `exec` still preserves argv, cwd, stdout, stderr, signals, and child exit status. `/dev/null` is portable across Orbit's supported macOS/Linux hosts. The current focused regression passed with 1 test and 6 assertions.
- The stdin regression's open `InputStream`, fake `php`, timeout, exit-23 assertion, and `finally` cleanup are appropriately functional and do not produce the prior source-only false positive.
- The serial-stdin harness signal is distinct from the older parallel-bootstrap signal; no guardrail duplication finding was found.
- The recursive `getOutput()` stream-unwrapping path has no direct focused regression despite being named in the core packet, but the implementation was unchanged by this merge and current Laravel `OutputStyle` consumers return a real underlying `OutputInterface`. Treat this as residual coverage risk, not a defect finding.

## Evidence reviewed

- Actual merge and feature diffs: `ab6d1c9e8` / `9ae08efd8` and `1c24084e0` / `9ec756c55`.
- Current callers: `StreamedStepTree`, `StreamsGatewayProgress`, `UpdateHumanProgressRenderer`, and `UpdateAllHumanProgressRenderer`.
- Archived packets and evidence after `bin/orbit-session-index --check` reported current: both CLI Pest archives, the core injected-output archive, reviewer reports, initial/final analyzers, quality artifacts, PTY summaries, and retained topology `dev-a9d572` proof.
- The archived CLI PTY matrix proves stdin detachment removed the hang and isolated the old global-STDOUT leak. The core retained proof establishes the default decorated TTY path, but not explicit non-decoration.
- `PRODUCT_DECISIONS.md` has no conflicting direction; command/output guidance requires injected/non-decorated output to remain free of ANSI noise.

## Focused verification on current main

- `bin/orbit-session-index --check` — passed, index current.
- `bash -n bin/orbit-cli-pest` — passed.
- Focused gateway stdin regression — 1 passed, 6 assertions.
- Core `LiveRepaintOutputTest` + `StreamedStepTreeTest` — 10 passed, 23 assertions.
- CLI update/stream renderer tests — 50 passed, 228 assertions.
- `git show --check` and merge-range `git diff --check` for both merges — passed.
- Real-PTY undecorated `ConsoleOutput` probe — process exit 0, but semantic reproduction confirmed `supports=true` and 52 ANSI CSI sequences while `isDecorated=false`.
- No `composer test:e2e*` command was run or delegated.

VERDICT: changes-required
