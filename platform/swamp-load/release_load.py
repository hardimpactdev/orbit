#!/usr/bin/python3
"""Pinned native publication. The trusted Gateway caller holds its release lock.

prepare keeps custody until the same caller verifies its configured token and
acknowledges the exact receipt. This entry never restarts or admits native work.
"""
import concurrent.futures
import datetime
import fcntl
import hashlib
import importlib.util
import json
import os
import re
import ssl
import stat
import subprocess
import sys
import time
import urllib.request
from pathlib import Path

sys.dont_write_bytecode = True
sys.path.insert(0, str(Path(__file__).resolve().parent))
import manifest_grants


def workflow_identity(path):
    import yaml
    value = yaml.safe_load(path.read_text())
    require(isinstance(value, dict) and isinstance(value.get("id"), str)
            and isinstance(value.get("name"), str), "workflow_identity_unreadable")
    return value["id"], value["name"]

SCHEMA = "orbit.swamp.release-load.v1"
CHECKOUT = Path("/home/orbit/apps/orbit-monorepo-staging/checkout")
STATE = Path("/var/lib/orbit-swamp-main-load/releases")
LOCK = Path("/home/orbit/.local/state/orbit-swamp/serve-load.lock")
TOKEN = Path("/home/orbit/.config/swamp/serve-driver-token")
SERVE = "orbit-process-392-swamp-serve.service"
MODULES = ("release_load.py", "manifest_grants.py")
IDENTITY = ("schema", "release_sha", "release_id", "gateway_release_record", "target_node_id", "attempt_id")
LIMITATIONS = [
    "workflow.get equality excludes path and fields omitted by the official API, including placement/forEach",
    "extension version equality is not bundle-byte or helper-freshness proof",
    "workflow identities cover all current rows; full definition equality covers only changed workflow files",
    "full extension registry equality is not claimed",
    "hot reload only; no run is cancelled or inferred terminal",
]


class Refused(Exception):
    def __init__(self, code, retry_safe=False):
        self.code = code
        self.retry_safe = retry_safe
        super().__init__(code)


def require(condition, code):
    if not condition:
        raise Refused(code)


def now():
    return datetime.datetime.now(datetime.UTC).isoformat()


def digest(value):
    return hashlib.sha256(json.dumps(value, sort_keys=True, separators=(",", ":"),
                                     ensure_ascii=False).encode()).hexdigest()


def identity(request):
    require(isinstance(request, dict), "invalid_request")
    require(not set(request) - set(IDENTITY) - {"action", "receipt_hash"}, "unknown_request_field")
    require(request.get("schema") == SCHEMA and request.get("target_node_id") == 209, "invalid_target")
    sha = request.get("release_sha")
    require(isinstance(sha, str) and re.fullmatch(r"[a-f0-9]{40}", sha), "invalid_revision")
    require(request.get("release_id") == sha[:12], "release_identity_mismatch")
    record = request.get("gateway_release_record")
    require(type(record) is int and record > 0, "active_release_missing")
    attempt = request.get("attempt_id")
    require(isinstance(attempt, str) and re.fullmatch(r"[a-zA-Z0-9_-]{16,96}", attempt), "invalid_attempt")
    require(request.get("action") in ("prepare", "accept"), "invalid_action")
    return {key: request[key] for key in IDENTITY}


def save(path, value):
    temporary = path.with_suffix(".tmp")
    with temporary.open("w") as stream:
        os.chmod(temporary, 0o600)
        json.dump(value, stream, sort_keys=True)
        stream.flush()
        os.fsync(stream.fileno())
    temporary.replace(path)
    descriptor = os.open(path.parent, os.O_RDONLY | os.O_DIRECTORY)
    try:
        os.fsync(descriptor)
    finally:
        os.close(descriptor)


class Host:
    def __init__(self):
        self.checkout = CHECKOUT
        self.app = CHECKOUT / "swamp"
        self.deadline = time.monotonic() + 600

    def command(self, args, *, owner=False, timeout=60, input_text=None):
        environment = {"PATH": "/usr/local/bin:/usr/bin:/bin", "HOME": "/root",
                       "PYTHONDONTWRITEBYTECODE": "1", "SWAMP_NO_UPDATE_CHECK": "1",
                       "SWAMP_NO_TELEMETRY": "1"}
        if owner:
            args = ["/usr/sbin/runuser", "-u", "orbit", "--", "env", "-i",
                    "HOME=/home/orbit", "PATH=/home/orbit/.local/bin:/usr/local/bin:/usr/bin:/bin",
                    "SWAMP_HOME=/home/orbit/.swamp", "SWAMP_CONFIG_DIR=/home/orbit/.config/swamp",
                    "SWAMP_API_KEY_FILE=/home/orbit/.config/swamp/api-key",
                    "SWAMP_NO_UPDATE_CHECK=1", "SWAMP_NO_TELEMETRY=1",
                    "SWAMP_SERVE_TIMEOUT_MS=120000", *args]
        require(self.deadline > time.monotonic(), "load_deadline_exceeded")
        timeout = min(timeout, self.deadline - time.monotonic())
        result = subprocess.run(args, cwd=self.app, env=environment, input=input_text,
                                text=True, capture_output=True, timeout=timeout, check=False)
        require(result.returncode == 0 and len(result.stdout) <= 16 * 1024 * 1024, "command_refused_or_uncertain")
        return result.stdout.strip()

    def git(self, *args):
        return self.command(["git", "-C", str(self.checkout), *args], owner=True)

    def clean(self):
        require(not self.git("status", "--porcelain=v1", "--untracked-files=all"), "source_drift")

    def runtime(self):
        fields = self.command(["systemctl", "show", SERVE, "-p", "MainPID", "-p", "InvocationID"])
        value = dict(row.split("=", 1) for row in fields.splitlines())
        require(int(value.get("MainPID", "0")) > 0 and value.get("InvocationID"), "serve_unavailable")
        return {"pid": int(value["MainPID"]), "invocation": value["InvocationID"]}

    def preflight(self, sha):
        self.clean()
        before = self.runtime()
        accepted = json.loads((STATE.parent / "last-proof.json").read_text())
        old_sha = accepted.get("loaded_sha")
        runtime = accepted.get("runtime_after", {})
        require(isinstance(old_sha, str) and re.fullmatch(r"[a-f0-9]{40}", old_sha)
                and {key: runtime.get(key) for key in before} == before, "live_source_unverified")
        require(self.git("rev-parse", "HEAD") == old_sha, "starting_source_requires_recovery")
        # Fetch only the admitted object; never resolve latest main as a target.
        self.git("fetch", "--no-tags", "origin", sha)
        require(self.git("rev-parse", sha + "^{commit}") == sha, "commit_missing")
        return before, self.git("rev-parse", sha + ":swamp"), old_sha

    def stage(self, sha):
        self.git("checkout", "--detach", sha)
        self.clean()
        require(self.git("rev-parse", "HEAD") == sha, "source_revision_mismatch")

    def native(self, *args, remote=False):
        flags = (["--server", "ws://127.0.0.1:9418", "--token-file", str(TOKEN)]
                 if remote else ["--repo-dir", str(self.app)])
        return json.loads(self.command(["/home/orbit/.local/bin/swamp", *args, *flags, "--json"], owner=True))

    def reload(self):
        result = json.loads(self.command([
            "/home/orbit/.local/bin/swamp", "serve", "reload", "--server", "ws://127.0.0.1:9418",
            "--token-file", str(TOKEN), "--json"], owner=True, timeout=150))
        require(result.get("success") is True and not result.get("errors"), "native_reload_unconfirmed")
        # Never retain raw native errors or arbitrary response fields in a public receipt.
        return {"success": True, "errors": []}

    def verify(self, old_sha, sha):
        rows = self.native("workflow", "list").get("results")
        require(isinstance(rows, list) and rows, "workflow_inventory_unreadable")
        require(all(isinstance(row, dict) and isinstance(row.get("id"), str)
                    and isinstance(row.get("name"), str) for row in rows), "workflow_identity_unreadable")
        identities = {(row["id"], row["name"]) for row in rows}
        require(len(identities) == len(rows), "workflow_identity_duplicate")
        served_rows = self.native("workflow", "list", remote=True).get("results")
        require(isinstance(served_rows, list) and all(isinstance(row, dict) and isinstance(row.get("id"), str)
                and isinstance(row.get("name"), str) for row in served_rows), "served_workflow_inventory_unreadable")
        require({(row["id"], row["name"]) for row in served_rows} == identities
                and len(served_rows) == len(rows), "served_workflow_inventory_mismatch")
        changed = self.git("diff", "--no-renames", "--name-only", old_sha + ".." + sha, "--", "swamp/workflows").splitlines()
        selected = []
        for relative in changed:
            path = self.checkout / relative
            if path.is_file() and path.suffix in (".yaml", ".yml"):
                workflow_id, name = workflow_identity(path)
                require((workflow_id, name) in identities, "changed_workflow_identity_missing")
                selected.append({"id": workflow_id, "name": name})

        def compare(row):
            # list gives identities/counts only, so full gets remain necessary for DAG/task equality.
            disk = self.native("workflow", "get", row["id"])
            served = self.native("workflow", "get", row["id"], remote=True)
            require(disk.get("id") == row["id"] and served.get("id") == row["id"], "workflow_identity_mismatch")
            disk = {key: value for key, value in disk.items() if key != "path"}
            served = {key: value for key, value in served.items() if key != "path"}
            require(disk == served, "served_workflow_mismatch")
            return {"id": row["id"], "name": row["name"], "disk_hash": digest(disk),
                    "served_hash": digest(served), "equal": True,
                    "comparison_scope": "official_exposed_fields_except_path"}

        with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
            workflows = list(pool.map(compare, selected))
        # Retain the existing, installed post-reload source-version audit. Do not create another parser.
        spec = importlib.util.spec_from_file_location("release_behavior_versions", "/usr/local/libexec/behavior_versions.py")
        audit_module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(audit_module)
        audit = audit_module.audit(self.app, lambda name: self.native("behavior", "type", "describe", name,
                                                                    remote=True)["version"])
        require(audit.get("checked", 0) > 0 and audit["checked"] == audit.get("matched")
                and not audit.get("errors") and not audit.get("mismatches"), "served_extension_version_mismatch")
        return {"workflows": workflows, "workflow_inventory": {
            "equal": True, "disk_count": len(rows), "served_count": len(served_rows),
            "scope": "all_id_name_pairs", "before_sha": old_sha,
            "changed_files": len(changed), "full_definition_checks": len(selected),
            "cli_get_calls": 2 * len(selected)}, "extension_audit": {
            "checked": audit["checked"], "matched": audit["matched"], "mismatches": [],
            "scope": audit.get("scope"), "limit": audit.get("limit"),
            "registry_equality": False}}

    def grants(self, sha, directory):
        raw = (self.app / "config/gateway-workflow-grants.json").read_bytes()

        def native(args):
            remaining = self.deadline - time.monotonic()
            require(remaining > 0, "load_deadline_exceeded")
            return manifest_grants.native(args, str(self.app), timeout=min(90, remaining))

        result = manifest_grants.reconcile(raw, sha, directory,
                                          native)
        return {"source_sha": sha, "hash": hashlib.sha256(raw).hexdigest(),
                "scope_count": len(manifest_grants.scopes(json.loads(raw))),
                "created_count": len(result["created"]), "checkpoint": str(directory / (sha + ".json")),
                "applied": result["state"] == "applied"}

    def health(self):
        context = ssl.create_default_context(cafile="/usr/local/share/ca-certificates/orbit-root.crt")
        for url in ("http://127.0.0.1:9418/health", "https://swamp.orbit/health"):
            remaining = self.deadline - time.monotonic()
            require(remaining > 0, "load_deadline_exceeded")
            with urllib.request.urlopen(url, context=context if url.startswith("https:") else None, timeout=min(5, remaining)) as response:
                require(response.status == 200 and json.load(response).get("status") == "ok", "health_unverified")
        return {"loopback_status": 200, "configured_ca_status": 200, "checked_at": now()}

    def unchanged(self, sha, runtime):
        self.clean()
        require(self.git("rev-parse", "HEAD") == sha, "source_changed_during_publication")
        require(self.runtime() == runtime, "unexpected_runtime_change")


def process(request, host, directory):
    expected = identity(request)
    directory.mkdir(mode=0o700, parents=True, exist_ok=True)
    pending = directory / "pending.json"
    receipt_path = directory / (expected["attempt_id"] + ".json")
    if request["action"] == "accept":
        require(receipt_path.is_file(), "prepared_receipt_missing")
        receipt = json.loads(receipt_path.read_text())
        require(all(receipt.get(key) == value for key, value in expected.items()), "receipt_identity_mismatch")
        require(request.get("receipt_hash") == receipt.get("receipt_hash"), "receipt_digest_mismatch")
        if receipt.get("state") == "verified":
            if pending.is_file() and json.loads(pending.read_text()).get("identity") == expected:
                pending.unlink()
            return receipt
        require(pending.is_file(), "custody_marker_missing")
        marker = json.loads(pending.read_text())
        require(marker.get("identity") == expected and marker.get("state") == "awaiting_gateway_verification",
                "attempt_requires_inspection")
        host.unchanged(expected["release_sha"], receipt["runtime_after"])
        host.health()
        save(directory.parent / "last-proof.json", {"loaded_sha": expected["release_sha"],
             "runtime_after": receipt["runtime_after"], "behavior_versions": receipt["extension_audit"],
             "workflow_inventory": receipt.get("workflow_inventory"), "ready_at": now(),
             "health": receipt["health"], "receipt_hash": receipt["receipt_hash"]})
        receipt.update(state="verified", completed_at=now())
        save(receipt_path, receipt)
        pending.unlink()
        return receipt

    require(not pending.exists() and not receipt_path.exists(), "attempt_requires_inspection")
    try:
        before, tree, old_sha = host.preflight(expected["release_sha"])
    except Exception as error:
        raise Refused("preflight_unavailable", retry_safe=True) from error
    marker = {"identity": expected, "state": "preparing", "phase": "source", "started_at": now()}
    save(pending, marker)
    phase = "source"
    try:
        host.stage(expected["release_sha"])
        phase = "reload"
        marker["phase"] = phase
        save(pending, marker)
        reload_result = host.reload()
        ack = now()
        phase = "manifest"
        marker["phase"] = phase
        save(pending, marker)
        manifest = host.grants(expected["release_sha"], directory / "grants")
        phase = "verification"
        marker["phase"] = phase
        save(pending, marker)
        evidence = host.verify(old_sha, expected["release_sha"])
        health = host.health()
        host.unchanged(expected["release_sha"], before)
        receipt = {**expected, **evidence, "requested_sha": expected["release_sha"],
                   "disk_sha": expected["release_sha"], "swamp_tree": tree,
                   "state": "awaiting_gateway_verification", "started_at": marker["started_at"],
                   "prepared_at": now(), "reload_ack_at": ack, "reload_result": reload_result,
                   "runtime_before": before, "runtime_after": before, "restarts": 0, "worker_restarts": 0,
                   "manifest": manifest, "health": health, "limitations": LIMITATIONS}
        receipt["receipt_hash"] = digest(receipt)
        save(receipt_path, receipt)
        marker.update(state="awaiting_gateway_verification", phase="gateway_verification")
        save(pending, marker)
        return receipt
    except Exception:
        marker.update(state="outcome_uncertain", phase=phase)
        save(pending, marker)
        raise


def main():
    attempt = None
    try:
        require(sys.argv[1:] == ["--stdin-json"] and os.geteuid() == 0, "entry_invocation_refused")
        raw = sys.stdin.read(65537)
        require(len(raw.encode()) <= 65536, "request_too_large")
        request = json.loads(raw)
        identity(request)
        attempt = request["attempt_id"]
        # No create and no symlink following: use the existing publication inode.
        descriptor = os.open(LOCK, os.O_RDONLY | os.O_NOFOLLOW)
        with os.fdopen(descriptor, "r") as lock:
            require(stat.S_ISREG(os.fstat(lock.fileno()).st_mode), "invalid_publication_lock")
            try:
                fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            except BlockingIOError as error:
                raise Refused("publication_busy", retry_safe=True) from error
            print(json.dumps(process(request, Host(), STATE), sort_keys=True))
        return 0
    except Exception as error:  # noqa: BLE001 -- public boundary must never print raw native errors or input
        phase = "entry"
        marker = STATE / "pending.json"
        if attempt and marker.is_file():
            try:
                value = json.loads(marker.read_text())
                if value.get("identity", {}).get("attempt_id") == attempt:
                    phase = value.get("phase", "entry")
            except (OSError, ValueError):
                phase = "checkpoint_unreadable"
        print(json.dumps({"schema": SCHEMA, "state": "outcome_uncertain", "attempt_id": attempt,
                          "code": error.code if isinstance(error, Refused) else "load_refused_or_uncertain",
                          "phase": phase, "last_verified_sha": None,
                          "retry_safe": isinstance(error, Refused) and error.retry_safe, "checkpoint": str(marker)}))
        return 1


if __name__ == "__main__":
    sys.exit(main())
