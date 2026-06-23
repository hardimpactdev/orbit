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
3. Inspect `summary.txt` first for exit code, duration, idle gaps, and output paths.
4. Inspect `chunks.jsonl` when cadence matters. Each row has elapsed seconds, byte count, and decoded text for one terminal read.
5. Compare frame times against the expected cadence. For a 300ms blinker, successive visible indicator changes should usually be around `0.30s`, allowing ordinary scheduler noise.
6. Inspect `transcript.txt` for final shape, ANSI framing, wrapping, and missing progress states.
7. Keep the raw artifacts in `/tmp` or another disposable path unless they are needed as test evidence.

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

`transcript.txt` is a readable combined transcript.

`summary.txt` records the command, exit code, duration, maximum idle gap, and artifact paths.

## Verification Notes

- Do not rely on screenshots alone for liveness bugs. Screenshots show state, not cadence.
- Do not use a normal pipe when validating TTY-specific rendering. Pipes can disable decoration or change buffering.
- When a freeze is reported, look for a large `delta` between chunks while an active spinner/blinker should have continued repainting.
- When output appears only at the end, confirm whether the process was actually attached to a PTY and whether the CLI classified the output as live/decorated.
- For interactive commands, prefer a retained terminal after the artifact run so the user can inspect the final behavior manually.
- Do not ask the user to review terminal UX until an agent has inspected the
  PTY frame artifacts and either found no blockers or reported the exact
  mismatch.

## Script

Use `scripts/capture_pty_frames.py`. Read the script only when changing its behavior; otherwise run it directly.
