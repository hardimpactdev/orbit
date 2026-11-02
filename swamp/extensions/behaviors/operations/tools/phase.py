"""Retained shared Tool Manager setup; package work uses native behavior steps."""
import json
import subprocess
import sys

APT = ["/usr/bin/apt-get", "-o", "DPkg::Lock::Timeout=300"]

ROOT = ["/usr/bin/sudo", "-n", "--", "/usr/bin/env", "DEBIAN_FRONTEND=noninteractive", "LC_ALL=C"]

BUNDLE, *SETUP = sys.stdin.read().split("\x1e")

BUNDLE = json.loads(BUNDLE)

INPUT, REPOSITORY = BUNDLE["input"], BUNDLE["repository"]

def run(argv, root=False, stdin=None):
    result = subprocess.run([*ROOT, *argv] if root else argv, input=stdin, capture_output=True, text=True, check=False,
                            **({} if stdin is not None else {"stdin": subprocess.DEVNULL}))
    if result.returncode:
        sys.stderr.write(result.stderr[-4096:])
    return result

def script(name, root=False, **extra):
    """Run one bound manager script with the public input; its last stdout line is its JSON, or None."""
    result = run(["/usr/bin/python3", "-I", "-c", BUNDLE[name], json.dumps({**INPUT, **extra})], root)
    try:
        return json.loads(result.stdout.strip().splitlines()[-1]) if result.returncode == 0 else None
    except (ValueError, IndexError):
        return None

def setup(manager_body, apt_body):
    """First use on Linux: OS prerequisites, the pinned apt packages, then the pinned manager installer."""
    packages = BUNDLE["setup_packages"]
    if packages and (run([*APT, "update"], root=True).returncode or run(
            [*APT, "install", "--yes", "--no-install-recommends", "--no-remove", "--", *packages], root=True).returncode):
        return False
    return all(not body or run(["/usr/bin/python3", "-I", "-c", body], root=True).returncode == 0
               for body in (apt_body, manager_body))

def set_up():
    """Set up the Tool Manager: read it, and on a Linux first use install it at its pinned version, then read it again."""
    facts = script("query", manager_only=True)
    if SETUP and isinstance(facts, dict) and facts.get("scope") == "absent":
        facts = script("query", manager_only=True) if setup(*SETUP) else None
    return {"facts": facts}, isinstance(facts, dict) and facts.get("scope", "present") == "present" and "manager_version" in facts

VALUE, OK = set_up()
print(json.dumps(VALUE, separators=(",", ":")))
sys.exit(0 if OK else 1)
