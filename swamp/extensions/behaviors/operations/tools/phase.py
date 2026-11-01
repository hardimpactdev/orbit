"""One Tool phase on the Node, run as the managed account by official SSH; root work goes through sudo -n.

argv: phase, then the phase's own public arguments. stdin: the bundle JSON (public intent and the bound manager scripts),
then for a first use the manager and apt installer sources, each after an ASCII record separator. The installers
carry the Vite+ lock, larger than one exec argument may be. Prints one public JSON object; a phase that did not do its job exits 1, so its step fails natively. A failed command's stderr tail goes to stderr; its output never reaches the result."""
import json
import subprocess
import sys

# A busy apt or dpkg lock (unattended-upgrades) waits within the phase budget, then fails (Nick, 2026-11-01).
APT = ["/usr/bin/apt-get", "-o", "DPkg::Lock::Timeout=300"]
ROOT = ["/usr/bin/sudo", "-n", "--", "/usr/bin/env", "DEBIAN_FRONTEND=noninteractive", "LC_ALL=C"]
PHASE = sys.argv[1]
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


def repository(action):
    result = run(["/usr/bin/python3", "-I", "-c", BUNDLE["repository_body"], action, json.dumps(REPOSITORY)], root=True)
    try:
        return json.loads(result.stdout.strip().splitlines()[-1])
    except (ValueError, IndexError):
        return {"ready": False, "error_code": "tool.repository_failed"}


def ready(facts):
    return isinstance(facts, dict) and facts.get("scope", "present") == "present" and "error_code" not in facts \
        and facts.get("complete") is True and isinstance(facts.get("versions"), list) and len(facts["versions"]) <= 1


def present(facts):
    return ready(facts) and len(facts["versions"]) == 1


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


def source():
    """Add or update the signed apt source: the key is checked by its fingerprint."""
    value = repository("prepare")
    return value, value.get("ready") is True


def check():
    """Check the candidate: the package as installed now, and the version the manager would install."""
    facts = script("query")
    candidate = script("candidate", before=facts, repository=REPOSITORY) if ready(facts) else None
    return {"facts": facts, "candidate": candidate}, True


def write():
    """Install or upgrade the decided candidate, then read the package back."""
    before = script("query")
    written = script("write", BUNDLE["root"], before=before, candidate=json.loads(sys.argv[2]), repository=REPOSITORY)
    return {"written": written is not None, "facts": script("query")}, written is not None


def plan():
    """The installed package and, for apt and brew, what removing it would also remove."""
    facts = script("query")
    return {"facts": facts, **({"plan": script("plan", before=facts)} if "plan" in BUNDLE and present(facts) else {})}, True


def remove():
    """Remove exactly this package; dpkg as root, every other manager as the managed account."""
    return {}, script("remove", BUNDLE["root"], before=script("query")) is not None


def gone():
    """Check it is gone: the Tool only counts as removed when the manager shows the package absent."""
    facts = script("query")
    return {"facts": facts}, ready(facts) and not present(facts)


def remove_source():
    """Drop the apt source, its key and its preference, once no Tool uses them."""
    value = repository("cleanup")
    return value, value.get("ready") is True


VALUE, OK = {"setup": set_up, "source": source, "check": check, "write": write, "plan": plan, "remove": remove,
             "gone": gone, "remove_source": remove_source}[PHASE]()
print(json.dumps(VALUE, separators=(",", ":")))
sys.exit(0 if OK else 1)
