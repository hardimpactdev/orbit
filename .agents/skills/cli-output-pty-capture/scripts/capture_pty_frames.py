#!/usr/bin/env python3
import argparse
import json
import os
import pty
import select
import shlex
import signal
import sys
import time
from pathlib import Path


def parse_args():
    parser = argparse.ArgumentParser(
        description="Run a command under a pseudo-terminal and timestamp output frames.",
    )
    parser.add_argument("--output-dir", required=True, help="Directory for chunks.jsonl, transcript.txt, and summary.txt.")
    parser.add_argument("--timeout", type=float, default=300.0, help="Maximum command runtime in seconds.")
    parser.add_argument("--idle-timeout", type=float, default=60.0, help="Maximum seconds without PTY output before termination.")
    parser.add_argument("command", nargs=argparse.REMAINDER, help="Command to run after --.")

    args = parser.parse_args()

    if args.command and args.command[0] == "--":
        args.command = args.command[1:]

    if not args.command:
        parser.error("provide a command after --")

    return args


def terminate(pid):
    try:
        os.kill(pid, signal.SIGTERM)
    except ProcessLookupError:
        return

    deadline = time.monotonic() + 2.0

    while time.monotonic() < deadline:
        finished_pid, _ = os.waitpid(pid, os.WNOHANG)

        if finished_pid == pid:
            return

        time.sleep(0.05)

    try:
        os.kill(pid, signal.SIGKILL)
    except ProcessLookupError:
        return


def exit_code_from_status(status):
    if os.WIFEXITED(status):
        return os.WEXITSTATUS(status)

    if os.WIFSIGNALED(status):
        return 128 + os.WTERMSIG(status)

    return 1


def main():
    args = parse_args()
    output_dir = Path(args.output_dir)
    output_dir.mkdir(parents=True, exist_ok=True)

    chunks_path = output_dir / "chunks.jsonl"
    transcript_path = output_dir / "transcript.txt"
    summary_path = output_dir / "summary.txt"

    started = time.monotonic()
    last_output = started
    previous_output = None
    max_delta = 0.0
    timed_out = False
    idle_timed_out = False
    status = None

    pid, fd = pty.fork()

    if pid == 0:
        os.execvp(args.command[0], args.command)

    with chunks_path.open("w", encoding="utf-8") as chunks, transcript_path.open("w", encoding="utf-8") as transcript:
        while True:
            now = time.monotonic()

            if now - started > args.timeout:
                timed_out = True
                terminate(pid)
                break

            if now - last_output > args.idle_timeout:
                idle_timed_out = True
                terminate(pid)
                break

            ready, _, _ = select.select([fd], [], [], 0.05)

            if fd in ready:
                try:
                    data = os.read(fd, 4096)
                except OSError:
                    data = b""

                if not data:
                    break

                now = time.monotonic()
                elapsed = now - started
                delta = 0.0 if previous_output is None else now - previous_output
                previous_output = now
                last_output = now
                max_delta = max(max_delta, delta)
                text = data.decode("utf-8", errors="replace")

                chunks.write(json.dumps({
                    "elapsed": round(elapsed, 6),
                    "delta": round(delta, 6),
                    "bytes": len(data),
                    "text": text,
                }, ensure_ascii=False) + "\n")
                chunks.flush()
                transcript.write(text)
                transcript.flush()

            finished_pid, child_status = os.waitpid(pid, os.WNOHANG)

            if finished_pid == pid:
                status = child_status
                break

    if status is None:
        try:
            _, status = os.waitpid(pid, 0)
        except ChildProcessError:
            status = 1

    try:
        os.close(fd)
    except OSError:
        pass

    duration = time.monotonic() - started
    exit_code = exit_code_from_status(status)

    if timed_out or idle_timed_out:
        exit_code = exit_code if exit_code != 0 else 124

    summary = [
        f"command: {shlex.join(args.command)}",
        f"exit_code: {exit_code}",
        f"duration: {duration:.3f}s",
        f"max_delta_between_chunks: {max_delta:.3f}s",
        f"timed_out: {str(timed_out).lower()}",
        f"idle_timed_out: {str(idle_timed_out).lower()}",
        f"chunks: {chunks_path}",
        f"transcript: {transcript_path}",
    ]

    summary_path.write_text("\n".join(summary) + "\n", encoding="utf-8")
    print(summary_path.read_text(encoding="utf-8"), end="")

    return exit_code


if __name__ == "__main__":
    sys.exit(main())
