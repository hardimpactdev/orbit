"""Run the ordered admitted commands as one F9 phase, using coreutils timeouts.

The metadata reader prefixes the unchanged shared project_environment.py library.
Only public intent is in argv; the complete command bundle is read from stdin.
"""

import hashlib
import json
import os
import pwd
import re
import subprocess
import sys
import tempfile
from pathlib import Path


class PhaseRefused(Exception):
    pass


def require(value, code="instance.step_not_admitted"):
    if not value:
        raise PhaseRefused(code)


def public_tail(stream, values):
    size = stream.tell()
    stream.seek(max(0, size - 1024 * 1024))
    raw = stream.read().decode("utf-8", errors="replace")
    if size > 1024 * 1024:
        raw = raw.partition("\n")[2]
    for value in sorted({v for v in values if v}, key=len, reverse=True):
        raw = raw.replace(value, "[REDACTED]")
    raw = re.sub(r"(?i)(authorization\s*:\s*(?:bearer|basic)\s+)\S+", r"\1[REDACTED]", raw)
    raw = re.sub(r"(?i)((?:password|secret|token|api[_-]?key)\s*[=:]\s*)[^\s,;]+", r"\1[REDACTED]", raw)
    raw = re.sub(r"(?s)-----BEGIN .*?-----END [^-]+-----", "[REDACTED]", raw)
    if "-----BEGIN " in raw:
        raw = raw.partition("-----BEGIN ")[0]
    encoded = raw.encode()
    tail = encoded[-2048:].decode("utf-8", errors="ignore")
    if len(encoded) > 2048:
        tail = tail.partition("\n")[2]
    return tail


def execute(request, commands):
    intent = request["intent"]
    deployment = intent["deployment"]
    phase = request["phase"]
    steps = [step for step in deployment["steps"] if step["phase"] == phase]
    result = {"ready": False, "warnings": 0, "commands": [], "failed_step": None, "output_tail": None}
    try:
        require(phase in ("before_activation", "after_activation"))
        require(isinstance(commands, list) and len(commands) == len(steps))
        # Refuse the entire stale bundle before running any command.
        for step, command in zip(steps, commands, strict=True):
            require(isinstance(command, str) and 0 < len(command.encode()) <= 16384 and "\0" not in command)
            require(hashlib.sha256(command.encode()).hexdigest() == step["command_digest"])
            require(type(step["timeout_seconds"]) is int and 1 <= step["timeout_seconds"] <= 3600)
        user = deployment["node_user"] if deployment["variant"] == "development" else deployment["user"]
        account = pwd.getpwnam(user)
        require(account.pw_uid > 0 and account.pw_uid == os.getuid(), "instance.step_layout_invalid")
        home = Path(deployment["home"])
        root = home if deployment["variant"] == "development" else home / "releases" / request["source"]["release"]["name"]
        require(root.is_dir() and not root.is_symlink() and str(root.resolve()) == str(root)
                and root.stat().st_uid == account.pw_uid, "instance.step_layout_invalid")
        environment = load_project_environment(str(home))  # noqa: F821 - shared pure library, unchanged prefix
        secrets = [v for value in environment.values() for v in value.splitlines() if v]
        environment.update(HOME=account.pw_dir, USER=user, LOGNAME=user, PATH="/usr/local/bin:/usr/bin:/bin")
        if deployment["variant"] == "development":
            environment.update(ORBIT_SEED_PATH=str(root), ORBIT_SEED_COMMIT=request["source"]["commit"])
        runtime = request.get("runtime")
        prefix = []
        warnings = []
        if runtime is not None:
            selector = runtime["package_manager"] + "@" + runtime["package_manager_version"]
            if runtime.get("package_manager_hash"):
                selector += "+" + runtime["package_manager_hash"]
            prefix = [runtime["store"] + "/bin/vp", "env", "exec", "--node", runtime["node_version"],
                      "--package-manager", selector]
            environment["VP_HOME"] = runtime["store"]
        for step, command in zip(steps, commands, strict=True):
            result["failed_step"] = phase + "." + step["name"]
            with tempfile.TemporaryFile() as output, tempfile.TemporaryFile(mode="w+") as program:
                program.write(command)
                program.flush()
                program_path = f"/proc/{os.getpid()}/fd/{program.fileno()}"
                completed = subprocess.run(
                    ["/usr/bin/timeout", "--signal=TERM", "--kill-after=2s", str(step["timeout_seconds"]) + "s",
                     *prefix, "/bin/bash", "--noprofile", "--norc", "-eu", program_path],
                    cwd=root, env=environment, stdin=subprocess.DEVNULL, stdout=output, stderr=output, check=False)
                tail = public_tail(output, [*secrets, *commands])
            timed_out = completed.returncode in (124, 137)
            result["commands"].append({"name": step["name"], "exit_code": 143 if completed.returncode == 124 else completed.returncode, "timed_out": timed_out})
            if completed.returncode != 0:
                if step["required"]:
                    result.update(error_code="deployment.step_failed", output_tail="\n".join([*warnings, tail])[:2048])
                    return result
                result["warnings"] += 1
                warnings.append(f"Best-effort step failed (exit {result['commands'][-1]['exit_code']}): {step['name']}")
                result["output_tail"] = "\n".join(warnings)[:2048]
        if deployment["variant"] == "development":
            branch = subprocess.run(["/usr/bin/git", "-C", str(root), "symbolic-ref", "--quiet", "--short", "HEAD"],
                                    capture_output=True, text=True, check=False)
            head = subprocess.run(["/usr/bin/git", "-C", str(root), "rev-parse", "HEAD"],
                                  capture_output=True, text=True, check=False)
            require(branch.returncode == 0 and branch.stdout.strip() == deployment["branch"], "deployment.branch_changed")
            require(head.returncode == 0 and head.stdout.strip() == request["source"]["commit"], "deployment.identity_changed")
        return {**result, "ready": True, "failed_step": None}
    except (PhaseRefused, ValueError, OSError, KeyError, TypeError) as error:
        code = str(error) if isinstance(error, (PhaseRefused, ValueError)) and re.fullmatch(r"[a-z0-9_.-]{1,100}", str(error)) else "instance.step_start_failed"
        return {**result, "error_code": code}


if __name__ == "__main__":
    try:
        request = json.loads(sys.argv[1])
        if len(sys.argv) > 2:
            request.update(phase=sys.argv[2], source=json.loads(sys.argv[3]))
        if len(sys.argv) > 4:
            request["runtime"] = json.loads(sys.argv[4])
        result = execute(request, json.load(sys.stdin))
    except (ValueError, OSError, KeyError, TypeError, IndexError):
        result = {"ready": False, "error_code": "instance.step_not_admitted"}
    print(json.dumps(result, separators=(",", ":")), flush=True)
    if not result["ready"]:
        print(result["error_code"], file=sys.stderr)
        sys.exit(1)
