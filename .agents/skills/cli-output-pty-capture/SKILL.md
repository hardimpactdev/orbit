---
name: cli-output-pty-capture
description: Record, timestamp, and analyze command-line output under a real pseudo-terminal. Use when fixing or validating CLI UX/output issues involving spinners, blinking indicators, progress trees, ANSI repainting, cursor movement, TTY detection, stream idleness, or reports that output freezes, flickers, skips frames, buffers unexpectedly, or behaves differently in a terminal than in tests.
---

# CLI Output PTY Capture

## Overview

Use this skill to prove terminal rendering behavior with timed PTY frames. The goal is to capture what a real terminal receives, not just what a process prints when stdout is piped.

## Workflow

1. Reproduce the reported CLI behavior in the same runtime context when possible: local terminal, Solo retained terminal, Incus VM, bundled binary, dev launcher, CI shell, or non-interactive pipe.
2. Run the command through `scripts/capture_pty_frames.py` so stdout/stderr are attached to a pseudo-terminal.
3. Prove whether the CLI believed the stream was decorated/live. Record relevant
   environment such as `NO_COLOR`, `TERM`, the launcher path, and when possible
   the framework decoration check (for example `isDecorated=true`). If
   decoration is disabled, label the artifact as non-decorated evidence instead
   of using it to prove ANSI repainting or live-frame behavior.
4. Inspect `summary.txt` first for exit code, duration, idle gaps, and output paths.
5. Inspect `chunks.jsonl` when cadence matters. Each row has elapsed seconds, byte count, and decoded text for one terminal read.
6. Compare frame times against the expected cadence. For a 300ms blinker, successive visible indicator changes should usually be around `0.30s`, allowing ordinary scheduler noise.
7. Inspect `transcript.txt` for final shape, ANSI framing, wrapping, and missing progress states.
8. If the implementation changes after capture, create a fresh artifact
   directory. Do not ask for human UX review from stale pre-correction
   transcripts.
9. Keep the raw artifacts in `/tmp` or another disposable path unless they are needed as test evidence.

## Bordered Output Checks

When the command renders a box, panel, tree, or other fixed-width human output,
strip ANSI codes before judging the frame. Inspect the whole final frame, not
only the rows that changed.

Reject the proof when any visible line:

- exceeds the renderer-owned panel width;
- places content directly against the right border because wrapping did not
  happen;
- has a missing, duplicated, or shifted right border;
- relies on terminal auto-wrap instead of renderer-owned continuation lines; or
- duplicates a full issue/error detail in a summary row while also listing the
  same detail below.

For long issue details, resource labels, or failure messages, the acceptable
shape is renderer-owned wrapping with the border preserved on every
continuation line.

## Quick Start

```bash
python3 .agents/skills/cli-output-pty-capture/scripts/capture_pty_frames.py \
  --output-dir /tmp/orbit-pty-capture \
  --timeout 120 \
  --idle-timeout 15 \
  -- orbit update:all
```

The command after `--` is executed exactly as the PTY child command. Use the absolute path to a binary when validating an installed artifact instead of a development launcher.

## Retained VM Review

For retained Incus proof, prefer running the capture script from the same Solo
terminal shell inside the target VM, usually from `/home/orbit/orbit-run`:

```bash
python3 .agents/skills/cli-output-pty-capture/scripts/capture_pty_frames.py \
  --output-dir /tmp/orbit-pty-capture-<slug> \
  --timeout 240 \
  --idle-timeout 180 \
  -- ./apps/cli/orbit <command>
```

The CLI reviewer should inspect the artifacts before handing the retained
terminal to the user. Human inspection should be a confirmation step, not the
first time anyone checks cadence, liveness, wrapping, ANSI framing, or final
shape. If the capture script cannot run inside the VM, capture from the closest
equivalent PTY context and report the downgrade explicitly.

## Reading Artifacts

`chunks.jsonl` is the primary proof artifact:

- `elapsed`: seconds since the command started.
- `delta`: seconds since the previous terminal read.
- `bytes`: bytes read from the PTY.
- `text`: UTF-8 decoded text with invalid bytes replaced.

Invalid replacement characters in `text` can be a capture chunk-boundary
artifact when multibyte box-drawing bytes are split between reads. Check the
raw byte stream or final settled frame before reporting a renderer bug.

`transcript.txt` is a readable combined transcript.

`summary.txt` records the command, exit code, duration, maximum idle gap, and artifact paths.

## Verification Notes

- Do not rely on screenshots alone for liveness bugs. Screenshots show state, not cadence.
- Do not use a normal pipe when validating TTY-specific rendering. Pipes can disable decoration or change buffering.
- When a freeze is reported, look for a large `delta` between chunks while an active spinner/blinker should have continued repainting.
- When output appears only at the end, confirm whether the process was actually attached to a PTY and whether the CLI classified the output as live/decorated.
- When human panel output is under review, check semantic shape as well as
  liveness: row labels, status text, issue bullets, issue caps, summary
  placement, wrapping or truncation, maximum visible width, machine-key leakage,
  and whether in-progress frames omit terminal-only summary text when the
  contract requires that.
- For issue lists or long status strings, prove the final visible frame fits
  the expected terminal width. A passing text assertion is not enough when a
  real terminal can overflow the border.
- For interactive commands, prefer a retained terminal after the artifact run so the user can inspect the final behavior manually.
- Do not ask the user to review terminal UX until an agent has inspected the
  PTY frame artifacts and either found no blockers or reported the exact
  mismatch.

## Script

Use `scripts/capture_pty_frames.py`. Read the script only when changing its behavior; otherwise run it directly.
