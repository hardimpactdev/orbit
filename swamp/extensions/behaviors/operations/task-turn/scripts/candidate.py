import hashlib
import json
import pathlib
import subprocess
import sys


def inspect():
    value = json.load(sys.stdin)
    before = json.loads(sys.argv[1])
    if value["candidate"] is not None and before["head"] != value["candidate"]:
        raise SystemExit("tasks.head_moved")
    evidence = value["analysis_evidence"]
    if evidence is not None:
        source, result = evidence["input"], evidence["result"]
        provenance, artifact = result.get("provenance", {}), result.get("artifact", {})
        content = artifact.get("content", "").encode()
        expected = {
            "node_id": value["node_id"],
            "instance_id": value["instance_id"],
            "head": before["head"],
            "tree": before["tree"],
            "base": source.get("base"),
            "repository": source.get("repository"),
            "plan_sha256": source.get("plan_sha256"),
        }
        if (
            any(provenance.get(k) != v for k, v in expected.items())
            or len(content) > 65536
            or artifact.get("bytes") != len(content)
            or artifact.get("sha256") != hashlib.sha256(content).hexdigest()
        ):
            raise SystemExit("tasks.analysis_stale")
        cwd = pathlib.Path(value["checkout_path"])

        def git(*args):
            return subprocess.check_output(
                ["git", *args], cwd=cwd, stderr=subprocess.DEVNULL
            ).strip()

        git("merge-base", "--is-ancestor", provenance["base"], "HEAD")
        entry = cwd / "bin/task-analyze"
        if (
            entry.is_symlink()
            or not entry.is_file()
            or not entry.stat().st_mode & 0o111
            or entry.read_bytes().strip() != git("show", "HEAD:bin/task-analyze")
            or not git("ls-tree", "HEAD", "--", "bin/task-analyze").startswith(
                b"100755 "
            )
        ):
            raise SystemExit("tasks.analysis_stale")
        observed = {
            "branch": git("symbolic-ref", "--short", "HEAD").decode(),
            "repository": git("remote", "get-url", "origin").decode(),
            "entrypoint_sha256": hashlib.sha256(entry.read_bytes()).hexdigest(),
        }
        if any(provenance.get(k) != v for k, v in observed.items()):
            raise SystemExit("tasks.analysis_stale")
    return {"admitted": True}


try:
    result = inspect()
except SystemExit as error:
    code = str(error)
    if code not in ("tasks.head_moved", "tasks.analysis_stale"):
        code = "tasks.analysis_stale"
    result = {"admitted": False, "error_code": code}
except (OSError, ValueError, KeyError, subprocess.SubprocessError):
    result = {"admitted": False, "error_code": "tasks.analysis_stale"}
print(json.dumps(result))
