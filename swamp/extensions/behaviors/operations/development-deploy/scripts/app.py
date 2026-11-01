"""Adapt one stdin command to the existing app-step runtime and expose only its outcome."""
import hashlib
import json
import signal
import subprocess
import sys

from common import INPUT, ROOT, emit, need

# The assets resolver prepends the existing shared app-step runtime.
APP_RUNTIME = "__APP_STEP_RUNTIME__"


def action():
    step = INPUT["step"]
    command = sys.stdin.buffer.read(16385)
    need(len(command) <= 16384 and b"\0" not in command and hashlib.sha256(command).hexdigest() == step["command_digest"],
         "instance.step_not_admitted")
    target = {"variant": "checkout", "user": INPUT["intent"]["deployment"]["node_user"], "directory": ROOT,
              "seed_path": ROOT, "seed_commit": INPUT.get("selected") or ""}
    payload = {"project_id": INPUT["intent"]["deployment"]["project_id"], "target": target,
               "command_digest": step["command_digest"], "timeout_seconds": step["timeout_seconds"], "script": command.decode()}
    pins = INPUT.get("runtime")
    if pins is not None:
        payload["vp_environment"] = {**pins, "owner": target["user"]}
    child = None
    cancelled = False

    def cancel(*_):
        nonlocal cancelled
        cancelled = True
        if child is not None and child.poll() is None:
            child.send_signal(signal.SIGTERM)

    signal.signal(signal.SIGTERM, cancel)
    signal.signal(signal.SIGINT, cancel)
    if cancelled:
        return {"ready": False, "error_code": "operation.cancelled"}
    child = subprocess.Popen([sys.executable, "-c", APP_RUNTIME], stdin=subprocess.PIPE, stdout=subprocess.PIPE,
                             stderr=subprocess.DEVNULL)
    if cancelled:
        child.send_signal(signal.SIGTERM)
    stdout, _ = child.communicate(json.dumps(payload).encode())
    need(child.returncode == 0 and len(stdout) <= 8192, "instance.step_start_failed")
    result = json.loads(stdout)
    need("error_code" not in result, result.get("error_code", "instance.step_start_failed"))
    code = result["exit_code"]
    return {"exit_code": code, "timed_out": result["timed_out"],
            "warning": code != 0 and not step["required"],
            **({"ready": False, "error_code": "deployment.step_failed"} if code != 0 and step["required"] else {})}


emit(action)
