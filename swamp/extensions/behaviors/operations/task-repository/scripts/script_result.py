"""Shape the public script outcome from separate native action outputs."""

import json
import os

from repository_common import Refusal, emit


def result():
    before, after, execution = (
        json.loads(os.environ[key])
        for key in ("ORBIT_BEFORE", "ORBIT_AFTER", "ORBIT_EXECUTION")
    )
    for value in (before, execution, after):
        if "error_code" in value:
            return value
    if set(before) != {"head", "tree"} or set(after) != {"head", "tree"}:
        raise Refusal("tasks.script_execution_unknown")
    return {
        "outcome": "passed" if execution["exit_code"] == 0 else "failed",
        **execution,
        "changed": before != after,
        **after,
    }


if __name__ == "__main__":
    emit(result)
