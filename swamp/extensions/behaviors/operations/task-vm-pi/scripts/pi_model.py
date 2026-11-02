"""Write the captured Pi model definition using the existing vault-backed VM key."""
import json
import os
import sys
import tempfile
from pathlib import Path


def publish(origin, models, value):
    directory = Path("/home/orbit/.pi/agent")
    directory.mkdir(mode=0o700, parents=True, exist_ok=True)
    content = {"providers": {"orbit-task-vm": {"baseUrl": origin.rstrip("/") + "/v1", "api": "openai-responses", "apiKey": value,
               "models": [{"id": name, "reasoning": True} for name in models]}}}
    fd, candidate = tempfile.mkstemp(prefix=".models-", dir=directory)
    try:
        with os.fdopen(fd, "w") as output:
            json.dump(content, output, ensure_ascii=False, indent=2)
            output.flush()
            os.fchmod(output.fileno(), 0o600)
            os.fsync(output.fileno())
        os.replace(candidate, directory / "models.json")
    finally:
        Path(candidate).unlink(missing_ok=True)


if __name__ == "__main__":
    try:
        publish(sys.argv[1], json.loads(sys.argv[2]), sys.stdin.read().strip())
        print('{"configured":true}')
    except (OSError, ValueError, IndexError, TypeError):
        print("task_vm.runtime_failed", file=sys.stderr)
        raise SystemExit(1) from None
