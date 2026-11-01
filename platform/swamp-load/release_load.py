#!/usr/bin/python3
"""Pinned native publication. The trusted Gateway caller holds its release lock.

prepare keeps custody until the same caller verifies its configured token and
acknowledges the exact receipt. This entry never restarts or admits native work.
"""
import concurrent.futures
import datetime
import fcntl
import hashlib
import json
import os
import re
import ssl
import subprocess
import sys
import urllib.request
from pathlib import Path

sys.dont_write_bytecode = True
sys.path.insert(0, str(Path(__file__).resolve().parent))
import manifest_grants
import release_source_inventory

SCHEMA = "orbit.swamp.release-load.v1"
CHECKOUT = Path("/home/orbit/apps/orbit-monorepo-staging/checkout")
STATE = Path("/var/lib/orbit-swamp-main-load/releases")
LOCK = Path("/home/orbit/.local/state/orbit-swamp/serve-load.lock")
TOKEN = Path("/home/orbit/.config/swamp/serve-driver-token")
SERVE = "orbit-process-392-swamp-serve.service"
MODULES = ("release_load.py", "release_rpc.ts", "release_source_inventory.py", "manifest_grants.py")
IDENTITY = ("schema", "release_sha", "release_id", "gateway_release_record", "target_node_id", "attempt_id")
LIMITATIONS = [
    "workflow.get equality excludes path and fields omitted by the official API, including placement/forEach",
    "extension version equality is not bundle-byte or helper-freshness proof",
    "deleted registrations are reported; full registry equality is not claimed",
    "hot reload only; no run is cancelled or inferred terminal",
]


class Refused(Exception):
    def __init__(self, code):
        self.code = code
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
    require(not set(request) - set(IDENTITY) - {"action", "gateway_release_lock", "active_release_record",
                                               "receipt_hash", "configured_token_proof"}, "unknown_request_field")
    require(request.get("schema") == SCHEMA and request.get("target_node_id") == 209, "invalid_target")
    sha = request.get("release_sha")
    require(isinstance(sha, str) and re.fullmatch(r"[a-f0-9]{40}", sha), "invalid_revision")
    require(request.get("release_id") == sha[:12], "release_identity_mismatch")
    record = request.get("gateway_release_record")
    require(type(record) is int and record > 0, "active_release_missing")
    attempt = request.get("attempt_id")
    require(isinstance(attempt, str) and re.fullmatch(r"[a-zA-Z0-9_-]{16,96}", attempt), "invalid_attempt")
    require(request.get("gateway_release_lock") == "GatewayReleaseLock::run"
            and request.get("active_release_record") == record, "gateway_interlock_missing")
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

    def command(self, args, *, owner=False, timeout=60, input_text=None):
        environment = {"PATH": "/usr/local/bin:/usr/bin:/bin", "HOME": "/root",
                       "PYTHONDONTWRITEBYTECODE": "1", "SWAMP_NO_UPDATE_CHECK": "1",
                       "SWAMP_NO_TELEMETRY": "1"}
        if owner:
            args = ["/usr/sbin/runuser", "-u", "orbit", "--", "env", "-i",
                    "HOME=/home/orbit", "PATH=/home/orbit/.local/bin:/usr/local/bin:/usr/bin:/bin",
                    "SWAMP_HOME=/home/orbit/.swamp", "SWAMP_CONFIG_DIR=/home/orbit/.config/swamp",
                    "SWAMP_NO_UPDATE_CHECK=1", "SWAMP_NO_TELEMETRY=1",
                    "SWAMP_SERVE_TIMEOUT_MS=120000", *args]
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

    def stage(self, sha):
        self.clean()
        require(self.git("rev-parse", sha + "^{commit}") == sha, "commit_missing")
        # A pinned rollback may move backwards; it must remain on admitted main history.
        self.git("merge-base", "--is-ancestor", sha, "refs/remotes/origin/main")
        self.git("checkout", "--detach", sha)
        self.clean()
        require(self.git("rev-parse", "HEAD") == sha, "source_revision_mismatch")
        return self.git("rev-parse", "HEAD:swamp")

    def reload(self):
        result = json.loads(self.command([
            "/home/orbit/.local/bin/swamp", "serve", "reload", "--server", "ws://127.0.0.1:9418",
            "--token-file", str(TOKEN), "--json"], owner=True, timeout=150))
        require(result.get("success") is True and not result.get("errors"), "native_reload_unconfirmed")
        # Never retain raw native errors or arbitrary response fields in a public receipt.
        return {"success": True, "errors": []}

    def rpc(self, requests):
        script = Path(__file__).resolve().with_name("release_rpc.ts")
        return json.loads(self.command([
            "/home/orbit/.swamp/deno/deno", "run", "--quiet", "--no-prompt",
            "--allow-read=" + str(TOKEN), "--allow-net=127.0.0.1:9418", str(script), str(TOKEN)],
            owner=True, timeout=180, input_text=json.dumps(requests)))

    def workflows(self):
        rows = []
        for path in sorted((self.app / "workflows").glob("*.yaml")):
            text = path.read_text()
            workflow_id = re.search(r"^id: ([a-f0-9-]{36})$", text, re.MULTILINE)
            name = re.search(r"^name: ([a-zA-Z0-9_-]+)$", text, re.MULTILINE)
            require(workflow_id and name, "workflow_identity_unreadable")
            rows.append({"id": workflow_id[1], "name": name[1]})
        require(rows and len({row["id"] for row in rows}) == len(rows), "workflow_inventory_invalid")

        def local(row):
            result = json.loads(self.command([
                "/home/orbit/.local/bin/swamp", "workflow", "get", row["id"],
                "--repo-dir", str(self.app), "--json"], owner=True))
            return {**row, "disk": result}

        with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
            return list(pool.map(local, rows))

    def verify(self):
        workflows = self.workflows()
        inventory = release_source_inventory.inventory(self.app)
        ambiguous = [{"type": row["type"], "code": "source_version_ambiguous"}
                     for row in inventory if row.get("error")]
        require(not ambiguous, "source_version_ambiguous")
        requests = [{"type": "workflow.get", "payload": {"workflowIdOrName": row["id"]}}
                    for row in workflows]
        requests += [{"type": "model.type.describe", "payload": {"typeArg": row["type"]}}
                     for row in inventory]
        requests += [{"type": "model.type.search", "payload": {}},
                     {"type": "run.history", "payload": {"active": True}}]
        replies = self.rpc(requests)
        require(len(replies) == len(requests), "native_inventory_incomplete")
        proofs = []
        for row, reply in zip(workflows, replies):
            served = reply.get("data")
            require(isinstance(served, dict), "workflow_unreadable")
            disk = {key: value for key, value in row["disk"].items() if key != "path"}
            live = {key: value for key, value in served.items() if key != "path"}
            require(disk.get("id") == row["id"] and live.get("id") == row["id"], "workflow_identity_mismatch")
            require(disk == live, "served_workflow_mismatch")
            proofs.append({"id": row["id"], "name": row["name"], "disk_hash": digest(disk),
                           "served_hash": digest(live), "equal": True, "comparison_scope": "official_exposed_fields_except_path"})
        version_replies = replies[len(workflows):len(workflows) + len(inventory)]
        for row, reply in zip(inventory, version_replies):
            require(reply.get("data", {}).get("version") == row["expected"], "served_extension_version_mismatch")
        registry = replies[-2].get("data", {}).get("results")
        require(isinstance(registry, list), "registry_unreadable")
        coverage = release_source_inventory.coverage(inventory, [row["normalized"] for row in registry])
        require(not coverage["source_not_served"], "current_source_not_served")
        runs = replies[-1].get("runs")
        require(isinstance(runs, list) and all(isinstance(row, dict) and row.get("status") == "running"
                                             and isinstance(row.get("id"), str) for row in runs), "native_drain_unreadable")
        return {"workflows": proofs, "extension_audit": {
            "checked": len(inventory), "matched": len(inventory), "mismatches": [], "source_missing": [],
            "deleted": coverage["served_without_source"], "ambiguous": ambiguous},
            "drain": {"queried_at": now(), "active_running_run_ids": [row["id"] for row in runs]}}

    def grants(self, sha, directory):
        raw = (self.app / "config/gateway-workflow-grants.json").read_bytes()
        result = manifest_grants.reconcile(raw, sha, directory,
                                          lambda args: manifest_grants.native(args, str(self.app)))
        return {"source_sha": sha, "hash": hashlib.sha256(raw).hexdigest(),
                "scope_count": len(manifest_grants.scopes(json.loads(raw))),
                "created_count": len(result["created"]), "checkpoint": str(directory / (sha + ".json")),
                "applied": result["state"] == "applied"}

    def health(self):
        context = ssl.create_default_context(cafile="/usr/local/share/ca-certificates/orbit-root.crt")
        for url in ("http://127.0.0.1:9418/health", "https://swamp.orbit/health"):
            with urllib.request.urlopen(url, context=context if url.startswith("https:") else None, timeout=5) as response:
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
        proof = request.get("configured_token_proof")
        require(isinstance(proof, dict) and proof.get("principal") == "user:orbit-gateway"
                and proof.get("verified") is True and type(proof.get("check_count")) is int
                and proof["check_count"] >= receipt["manifest"]["scope_count"]
                and proof.get("manifest_hash") == receipt["manifest"]["hash"], "configured_gateway_token_unverified")
        host.unchanged(expected["release_sha"], receipt["runtime_after"])
        host.health()
        fresh = host.verify()
        require(fresh["workflows"] == receipt["workflows"]
                and fresh["extension_audit"] == receipt["extension_audit"], "prepared_definition_changed")
        receipt.update(state="verified", completed_at=now(), configured_token_proof={
            key: proof[key] for key in ("principal", "verified", "check_count", "manifest_hash")},
            drain=fresh["drain"])
        save(receipt_path, receipt)
        pending.unlink()
        return receipt

    require(not pending.exists() and not receipt_path.exists(), "attempt_requires_inspection")
    marker = {"identity": expected, "state": "preparing", "phase": "source", "started_at": now()}
    save(pending, marker)
    phase = "source"
    try:
        before = host.runtime()
        tree = host.stage(expected["release_sha"])
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
        evidence = host.verify()
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
        # Use the existing publication inode; never unlink or replace its lock file.
        with LOCK.open("a") as lock:
            try:
                fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            except BlockingIOError as error:
                raise Refused("publication_busy") from error
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
                          "retry_safe": False, "checkpoint": str(marker)}))
        return 1


if __name__ == "__main__":
    sys.exit(main())
