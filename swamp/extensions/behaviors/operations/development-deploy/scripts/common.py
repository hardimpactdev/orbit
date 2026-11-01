"""Public deployment identity and target-local data; no transport or supervisor."""
import json
import pwd
import subprocess
import sys
from typing import TYPE_CHECKING

if TYPE_CHECKING:
    from project_environment import load_project_environment



class Refused(Exception):
    pass


def need(value, code="deployment.identity_changed"):
    if not value:
        raise Refused(code)


def git(*args, env=None):
    result = subprocess.run(["/usr/bin/git", "-c", "core.hooksPath=/dev/null", "-c", "core.fsmonitor=false",
                             "-c", "credential.helper=", "--literal-pathspecs", *args], cwd=ROOT, env=env or ENV,
                            stdout=subprocess.PIPE, stderr=subprocess.DEVNULL)
    need(len(result.stdout) <= 4 * 1024 * 1024, "deployment.output_limit")
    return result


def text(*args):
    result = git(*args)
    need(result.returncode == 0, "deployment.source_failed")
    return result.stdout.decode().rstrip("\n")


def environment():
    try:
        return {**load_project_environment(ROOT), **ENV}
    except ValueError as error:
        raise Refused(str(error)) from None


def emit(action):
    try:
        result = action()
        print(json.dumps({"ready": True, **result}, separators=(",", ":")))
    except Refused as error:
        print(json.dumps({"ready": False, "error_code": str(error)}))
    except Exception:
        print('{"ready":false,"error_code":"deployment.native_action_failed"}')


MODE = sys.argv[1]
INPUT = json.loads(sys.argv[2])
INTENT = INPUT["intent"]
DEPLOY = INTENT["deployment"]
ROOT = DEPLOY["home"]
ACCOUNT = pwd.getpwnam(DEPLOY["node_user"])
ENV = {"HOME": ACCOUNT.pw_dir, "USER": ACCOUNT.pw_name, "LOGNAME": ACCOUNT.pw_name,
       "PATH": "/usr/local/bin:/usr/bin:/bin", "LANG": "C.UTF-8",
       "GIT_TERMINAL_PROMPT": "0", "GIT_CONFIG_NOSYSTEM": "1", "GIT_CONFIG_GLOBAL": "/dev/null"}

if len(sys.argv) > 3 and sys.argv[3]:
    INPUT["selected"] = sys.argv[3]
if len(sys.argv) > 4:
    INPUT["runtime"] = json.loads(sys.argv[4])
if len(sys.argv) > 5 and int(sys.argv[5]) >= 0:
    INPUT["step"] = DEPLOY["steps"][int(sys.argv[5])]
