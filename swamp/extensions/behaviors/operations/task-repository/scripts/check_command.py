"""Run one admitted setup, Project or deliverable command and redact its output."""

import json
import sys
from pathlib import Path

from check_common import command, inside
from repository_common import Refusal, checkout


def command_source(item):
    source = item["command"]
    if source == "@orbit/ci-caches":
        source = (Path(__file__).parent / "check_ci_caches.sh").read_text()
    return source


def execute():
    value = json.load(sys.stdin)
    item = json.loads(sys.argv[1])
    root = checkout(
        {**value, "checkout_path": sys.argv[2]} if len(sys.argv) > 2 else value
    )
    source = command_source(item)
    return command(
        source, inside(root, item.get("directory", ".")), value.get("ssr_port")
    )


if __name__ == "__main__":
    try:
        result = execute()
    except (OSError, ValueError, TypeError, KeyError, Refusal):
        result = {
            "exit_code": 255,
            "error_code": "tasks.check_execution_unknown",
            "output": "",
        }
    print(json.dumps(result), flush=True)
    # Official SSH okExitCodes:any records failed checks as observations. Native guards
    # use this exit code to skip later setup steps without an in-step control loop.
    sys.exit(result["exit_code"] if result["exit_code"] >= 0 else 255)
