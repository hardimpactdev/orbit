"""Real serve/worker bundles must carry each family's declared Python assets."""

import json
import os
import socket
import subprocess
import sys
import time
import unittest
import urllib.request
from pathlib import Path

import yaml

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from tests.native_harness import NativeTestCase


def reply(raw):
    for index, char in enumerate(raw):
        if char in "{[":
            try:
                return json.loads(raw[index:])
            except ValueError:
                pass
    raise AssertionError("Native JSON missing")


class ConnVaultRemoteAssets(NativeTestCase):
    native_timeout = 120

    def test_scripts_resolve_and_are_readable_on_a_separate_worker(self):
        self.copy_behaviors("operations/conn-revocation", "operations/vault-sync")
        vault = {
            "url": "https://vault.test",
            "client_id": "public-client",
            "organization_id": "organization",
            "collection_id": "collection",
            "ca_file": "/fixture/public-ca",
            "wanted": [],
            "ledger": [],
        }
        script = """python3 - <<'PYTHON'
import ast,json,os
from pathlib import Path
paths=[value for name,value in os.environ.items() if name.startswith("ASSET_PATH_")]
root=Path(os.environ['WORKER_CACHE']).resolve()
for raw in paths:
    path=Path(raw)
    assert path.resolve().is_relative_to(root), 'Asset did not resolve inside worker cache'
    ast.parse(path.read_text())
print(json.dumps({'readable':True,'count':len(paths)}))
PYTHON
"""
        steps = []
        for name, behavior, intent, paths in (
            (
                "vault",
                "@nckrtl/vault-sync-plan",
                vault,
                [
                    "${{ steps.vault.outputs.scripts." + name + " }}"
                    for name in (
                        "configure",
                        "login",
                        "unlock",
                        "sync",
                        "inventory",
                        "delete",
                        "write",
                        "logout",
                    )
                ],
            ),
            (
                "conn",
                "@nckrtl/conn-revocation-plan",
                {"url": "http://t3.test", "labels": [], "links": []},
                [
                    "${{ steps.conn.outputs.list_script }}",
                    "${{ steps.conn.outputs.revoke_script }}",
                ],
            ),
        ):
            steps.extend(
                [
                    {
                        "name": name,
                        "target": "orbit-node-209",
                        "labels": {"node": "209"},
                        "task": {
                            "type": "behavior",
                            "behaviorType": behavior,
                            "behaviorName": "assets-" + name,
                            "methodName": "assets",
                            "inputs": {"intent": intent},
                        },
                    },
                    {
                        "name": name + "-read",
                        "target": "orbit-node-209",
                        "labels": {"node": "209"},
                        "dependsOn": [
                            {"step": name, "condition": {"type": "succeeded"}}
                        ],
                        "task": {
                            "type": "behavior",
                            "behaviorType": "command/shell",
                            "behaviorName": "read-" + name,
                            "methodName": "execute",
                            "inputs": {
                                "run": script,
                                "env": {
                                    "ASSET_PATH_" + str(index): path
                                    for index, path in enumerate(paths)
                                },
                            },
                        },
                    },
                ]
            )
        workflow = {
            "id": "99a92a25-a12b-4895-82df-c2a514dca600",
            "name": "conn-vault-assets",
            "jobs": [{"name": "assets", "steps": steps}],
        }
        (self.repo / "workflows/workflow-conn-vault-assets.yaml").write_text(
            yaml.safe_dump(workflow)
        )
        self.cli(
            "access", "token", "mint", "fixture-admin", "--principal", "user:fixture"
        )
        admin = self.repo / "admin"
        admin.write_text(
            reply(
                self.cli("access", "token", "reveal", "fixture-admin", "--yes").stdout
            )["token"]
        )
        admin.chmod(0o600)
        with socket.socket() as sock:
            sock.bind(("127.0.0.1", 0))
            port = sock.getsockname()[1]
        url = f"ws://127.0.0.1:{port}"
        binary = os.environ.get("SWAMP_BIN", "swamp")
        cache = self.repo / "worker-cache"
        env = dict(
            os.environ,
            SWAMP_NO_TELEMETRY="1",
            SWAMP_NO_UPDATE_CHECK="1",
            WORKER_CACHE=str(cache),
        )
        for key in (
            "SWAMP_SERVER_URL",
            "SWAMP_SERVE_URL",
            "SWAMP_SERVER_TOKEN",
            "SWAMP_SERVER_TOKEN_FILE",
            "SWAMP_REPO_DIR",
            "SWAMP_CA_CERT",
            "SWAMP_EXTENSIONS_DIR",
        ):
            env.pop(key, None)
        serve = worker = None
        with (
            (self.repo / "serve.log").open("w") as log,
            (self.repo / "worker.log").open("w") as worker_log,
        ):
            try:
                serve = subprocess.Popen(
                    [
                        binary,
                        "serve",
                        "--host",
                        "127.0.0.1",
                        "--port",
                        str(port),
                        "--auth-mode",
                        "token",
                        "--admins",
                        "user:fixture",
                        "--remote-only",
                    ],
                    cwd=self.repo,
                    env=env,
                    stdout=log,
                    stderr=log,
                )
                for _ in range(100):
                    try:
                        urllib.request.urlopen(
                            f"http://127.0.0.1:{port}/ready", timeout=1
                        ).close()
                        break
                    except OSError:
                        self.assertIsNone(
                            serve.poll(), (self.repo / "serve.log").read_text()[-2000:]
                        )
                        time.sleep(0.1)
                flags = ("--server", url, "--token-file", str(admin))
                enrollment = self.repo / "enrollment"
                enrollment.write_text(
                    reply(
                        self.cli(
                            "worker",
                            "token",
                            "create",
                            "orbit-node-209",
                            "--duration",
                            "10m",
                            *flags,
                        ).stdout
                    )["token"]
                )
                enrollment.chmod(0o600)
                self.cli(
                    "access",
                    "token",
                    "mint",
                    "fixture-worker",
                    "--principal",
                    "worker:orbit-node-209",
                    *flags,
                )
                token = self.repo / "worker-server"
                token.write_text(
                    reply(
                        self.cli(
                            "access", "token", "reveal", "fixture-worker", "--yes"
                        ).stdout
                    )["token"]
                )
                token.chmod(0o600)
                worker = subprocess.Popen(
                    [
                        binary,
                        "worker",
                        "connect",
                        url,
                        "--token-file",
                        str(enrollment),
                        "--server-token-file",
                        str(token),
                        "--cache-dir",
                        str(cache),
                        "--label",
                        "node=209",
                    ],
                    cwd=self.repo,
                    env=env,
                    stdout=worker_log,
                    stderr=worker_log,
                )
                result = reply(
                    self.cli("workflow", "run", "conn-vault-assets", *flags).stdout
                )
                self.assertEqual(result["status"], "succeeded", result)
                history = reply(
                    self.cli("workflow", "history", "get", result["id"], *flags).stdout
                )
                outputs = {
                    step["name"]: step["outputs"]
                    for step in history["jobs"][0]["steps"]
                }
                self.assertEqual(
                    json.loads(outputs["vault-read"]["stdout"]),
                    {"readable": True, "count": 8},
                )
                self.assertEqual(
                    json.loads(outputs["conn-read"]["stdout"]),
                    {"readable": True, "count": 2},
                )
                self.assertTrue(list(cache.rglob("bw.py")))
            finally:
                for process in (worker, serve):
                    if process is not None and process.poll() is None:
                        process.terminate()
                        try:
                            process.wait(timeout=10)
                        except subprocess.TimeoutExpired:
                            process.kill()
                            process.wait(timeout=5)


if __name__ == "__main__":
    unittest.main()
