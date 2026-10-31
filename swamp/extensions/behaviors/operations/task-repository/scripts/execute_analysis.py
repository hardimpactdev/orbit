"""Execute the fixed analyzer once with its admitted public plan on stdin."""

import datetime
import hashlib
import json
import os
import subprocess
import sys
import tempfile
import time

from repository_common import Refusal, checkout, emit, executable, program_environment


def execute():
    value = json.load(sys.stdin)
    prepared, before = json.loads(sys.argv[1]), json.loads(sys.argv[2])
    if "error_code" in prepared:
        return prepared
    if before != {key: value[key] for key in ("head", "tree")}:
        raise Refusal("tasks.analysis_stale")
    root = checkout(value)
    if executable(root, "bin/task-analyze") != prepared["entrypoint_sha256"]:
        raise Refusal("tasks.analysis_stale")
    admitted = {
        key: value[key] for key in ("plan", "plan_sha256", "base", "head", "tree")
    }
    admitted["execution_id"] = hashlib.sha256(
        ("operation-" + str(value["operation_id"])).encode()
    ).hexdigest()[:32]
    started = datetime.datetime.now(datetime.UTC).isoformat().replace("+00:00", "Z")
    clock = time.monotonic()
    with (
        tempfile.TemporaryDirectory(prefix="orbit-task-program-") as scratch,
        tempfile.TemporaryFile() as output,
        tempfile.TemporaryFile() as error,
    ):
        result = subprocess.run(
            [os.path.join(root, "bin/task-analyze")],
            cwd=root,
            input=json.dumps(admitted, ensure_ascii=False).encode(),
            stdout=output,
            stderr=error,
            env={**program_environment(value.get("ssr_port")), "TMPDIR": scratch},
            check=False,
        )
        size = output.tell()
        output.seek(0)
        report = output.read(65537)
    if size > 65536:
        return {
            "error_code": "tasks.analysis_report_too_large",
            "schema": 1,
            "error": {
                "code": "tasks.analysis_report_too_large",
                "required_bytes": None,
            },
        }
    try:
        report = report.decode("utf-8", errors="strict")
    except UnicodeError:
        raise Refusal("tasks.analysis_report_invalid") from None
    return {
        "report": report,
        "exit_code": result.returncode,
        "execution_id": admitted["execution_id"],
        "started_at": started,
        "finished_at": datetime.datetime.now(datetime.UTC)
        .isoformat()
        .replace("+00:00", "Z"),
        "duration_ms": int((time.monotonic() - clock) * 1000),
    }


if __name__ == "__main__":
    emit(execute)
