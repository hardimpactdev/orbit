"""Outage re-proofs through public Operation reads and owned workload evidence."""
import hashlib
import json
import os
import shlex
import subprocess
import sys
from pathlib import Path

CASES = ("branch-in-use", "setup-fails-then-skips", "empty-environment", "setup-macros", "setup-redaction", "already-running")

# Only hashes of disposable private values leave the workload. In particular,
# a broken redactor cannot make the parity runner put a leaked value in argv.
WORKLOAD = r'''import hashlib,json,stat,sys
from pathlib import Path
root=Path(sys.argv[1])
if not root.is_absolute() or root.is_symlink():
    raise RuntimeError("proof-checkout-invalid")
case=sys.argv[2]
def marker(name):
    path=root/name
    return path.is_file() and stat.S_ISREG(path.lstat().st_mode)
result={"first":marker(".orbit-proof-first"),"failed":marker(".orbit-proof-failed"),
        "skipped":marker(".orbit-proof-skipped"),"empty":marker(".orbit-proof-empty"),
        "macro":marker(".orbit-proof-macro"),"running_setup":marker(".orbit-proof-running-setup")}
if case=="empty-environment":
    path=root/"empty-environment/.env"
    result["environment_file"]=path.is_file() and not path.is_symlink()
if case=="setup-redaction":
    path=root/".orbit-proof-private-values"
    if not stat.S_ISREG(path.lstat().st_mode) or path.stat().st_mode&0o077:
        raise RuntimeError("proof-private-values-invalid")
    values=json.loads(path.read_text())
    hashes=set()
    for value in values.values():
        for variant in (value,value.replace(chr(9),chr(92)+"t")):
            encoded=variant.encode()
            hashes.update(hashlib.sha256(encoded[i:i+8]).hexdigest() for i in range(len(encoded)-7))
    result["fragment_hashes"]=sorted(hashes)
print(json.dumps(result))
'''


def node_json(node, argv):
    result = subprocess.run([*shlex.split(os.environ["PROOF_EXEC"]), node, "--argv=" + json.dumps(argv)],
                            capture_output=True, text=True, timeout=45, check=False)
    if result.returncode:
        raise RuntimeError("proof-workload-or-cli-read-failed")
    return json.loads(result.stdout)


def command_result(selected):
    value = json.loads(selected["stdout"])
    if not isinstance(value, dict):
        raise TypeError("proof-selected-command-result-invalid")
    return value


def check_tail(value, marker):
    stderr = value.get("stderr")
    if not isinstance(stderr, str) or len(stderr.encode()) > 4096:
        raise RuntimeError("proof-stderr-tail-over-4096")
    if marker not in stderr:
        raise RuntimeError("proof-stderr-tail-not-from-failed-command")
    return stderr


def assert_private_absent(public, fragment_hashes):
    known = set(fragment_hashes)
    if not known:
        raise RuntimeError("proof-private-fragment-oracle-empty")

    def strings(value):
        if isinstance(value, str):
            yield value
        elif isinstance(value, dict):
            for key, item in value.items():
                yield key
                yield from strings(item)
        elif isinstance(value, list):
            for item in value:
                yield from strings(item)

    for value in strings(public):
        encoded = value.encode()
        if any(hashlib.sha256(encoded[i:i + 8]).hexdigest() in known for i in range(len(encoded) - 7)):
            raise RuntimeError("proof-private-value-or-fragment-exposed")


def validate(case, selected, operation, instance, workload):
    if case not in CASES:
        raise RuntimeError("proof-outage-case-unknown")
    success = case in ("empty-environment", "already-running")
    kind = "instance.run_steps" if case == "already-running" else "instance.create"
    if operation.get("kind") != kind or operation.get("status") != ("succeeded" if success else "failed"):
        raise RuntimeError("proof-operation-disposition-mismatch")
    if type(operation.get("id")) is not int or operation["id"] < 1 or operation.get("target_id") != instance.get("id"):
        raise RuntimeError("proof-operation-instance-mismatch")
    if not operation.get("started_at") or not operation.get("finished_at"):
        raise RuntimeError("proof-terminal-timestamps-missing")
    if not success and instance.get("status") == "active":
        raise RuntimeError("proof-failed-create-projected-active")
    result = operation.get("result") or {}
    if case == "branch-in-use":
        if operation.get("error_code") != "instance.branch_in_use":
            raise RuntimeError("proof-branch-refusal-not-preserved")
        if command_result(selected).get("ready") is not False:
            raise RuntimeError("proof-branch-result-not-refused")
    elif case == "setup-fails-then-skips":
        if not workload.get("first") or not workload.get("failed") or workload.get("skipped"):
            raise RuntimeError("proof-setup-order-or-skip-mismatch")
        command = command_result(selected)
        if command.get("exitCode") != 37:
            raise RuntimeError("proof-wrong-failed-command")
        check_tail(command, "expected-setup-failure")
    elif case == "empty-environment":
        if instance.get("status") != "active" or not workload.get("empty") or not workload.get("environment_file"):
            raise RuntimeError("proof-empty-environment-did-not-reach-setup")
        native = command_result(selected)
        if native.get("ready") is not True or native.get("instance_id") != instance["id"] or result.get("ready") is not True:
            raise RuntimeError("proof-empty-environment-not-projected")
    elif case == "setup-macros":
        if not workload.get("macro"):
            raise RuntimeError("proof-ci-caches-macro-never-completed")
        command = command_result(selected)
        if command.get("exitCode") != 31:
            raise RuntimeError("proof-macro-did-not-reach-following-command")
        check_tail(command, "macro-tail-marker")
    elif case == "setup-redaction":
        command = command_result(selected)
        if set(command) != {"exitCode", "stderr"} or command.get("exitCode") != 47 or workload.get("skipped"):
            raise RuntimeError("proof-redaction-command-or-skip-mismatch")
        stderr = check_tail(command, "public-redaction-tail-marker")
        if "[REDACTED]" not in stderr:
            raise RuntimeError("proof-no-redaction-was-observed")
        assert_private_absent({"selected": selected, "operation": operation}, workload.get("fragment_hashes", []))
    elif case == "already-running":
        if selected.get("status") != "succeeded" or not workload.get("running_setup"):
            raise RuntimeError("proof-already-running-list-did-not-execute")
        if result.get("outcome") != "succeeded" or result.get("no_op") is not False or instance.get("status") != "active":
            raise RuntimeError("proof-setup-success-not-projected")
        if operation.get("input", {}).get("list") != "setup":
            raise RuntimeError("proof-not-project-setup-list")
    return {"case": case, "passed": True, **({"github_token_path": "not_exercised"} if case in ("branch-in-use", "setup-fails-then-skips") else {})}


def main():
    case = sys.argv[1]
    selected = json.loads(Path(os.environ["PROOF_OUTPUTS"]).read_text())
    operation = node_json("operator", ["orbit", "operation:show", os.environ["PROOF_OPERATION_ID"], "--json"])
    instance = node_json("operator", ["orbit", "instance:show", str(operation["target_id"]), "--json"])
    workload = node_json("app-dev", ["python3", "-c", WORKLOAD, instance["checkout_path"], case])
    result = validate(case, selected, operation, instance, workload)
    if case == "branch-in-use":
        listing = node_json("operator", ["orbit", "instance:list", "--json"])
        owned = [row for row in listing["instances"] if row["project_id"] == instance["project_id"] and row["name"] == "default"]
        if len(owned) != 1 or owned[0]["id"] == instance["id"] or owned[0]["status"] != "active" or owned[0]["selected_branch"] != "main":
            raise RuntimeError("proof-owned-default-worktree-changed")
    print(json.dumps(result))


if __name__ == "__main__":
    main()
