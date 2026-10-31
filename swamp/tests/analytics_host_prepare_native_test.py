"""Actual Analytics request fan-out and shipped certificate commands on pinned Swamp."""

import base64
import getpass
import hashlib
import json
import os
import shutil
import subprocess
import sys
import unittest
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "tests"))
from native_harness import NativeTestCase  # noqa: E402


class AnalyticsHostPrepareNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        shutil.rmtree(self.repo / "models")
        certificates = self.repo / "extensions/behaviors/operations/certificates"
        certificates.mkdir(parents=True)
        origin = ROOT / "extensions/behaviors/operations/certificates"
        for name in (
            "manifest.yaml",
            "script_assets.ts",
            "input.ts",
            "signing.ts",
            "role-input.ts",
        ):
            shutil.copy(origin / name, certificates / name)
        shutil.copytree(origin / "scripts", certificates / "scripts")
        analytics = self.repo / "extensions/behaviors/operations/analytics"
        analytics.mkdir(parents=True)
        shutil.copy(
            ROOT / "extensions/behaviors/operations/analytics/host-intent.ts", analytics
        )
        self.home = self.repo / "host-home"
        self.home.mkdir()
        self.caddy = self.repo / "host-caddy"
        self.ca = self.repo / "ca.pem"
        self.user = getpass.getuser()
        # Only fixture account lookup and system paths differ.
        # Real key generation, CSR and OpenSSL code is unchanged.
        helper = certificates / "scripts/local.py"
        text = helper.read_text()
        text += f"""
from types import SimpleNamespace
CADDY = pathlib.Path({str(self.caddy)!r})
ANCHOR = pathlib.Path({str(self.ca)!r})
_original_getpwnam = pwd.getpwnam
def fixture_getpwnam(name):
    if name == {self.user!r}:
        return SimpleNamespace(pw_dir={str(self.home)!r},
                               pw_uid={os.getuid()}, pw_gid={os.getgid()})
    return _original_getpwnam(name)
pwd.getpwnam = fixture_getpwnam
"""
        helper.write_text(text)
        self.leaf = {
            "node_id": 9,
            "route_id": 42,
            "user": self.user,
            "agent_sha256": "a" * 64,
            "domain": "analytics.example.test",
            "scope": "route-42-router",
            "version": "b" * 16,
            "root_hash": "c" * 64,
        }
        self.inputs = {
            "operation_id": 7,
            "node_id": 17,
            "kind": "analytics.enable",
            "leaves": [self.leaf],
        }
        lower = yaml.safe_load(
            (ROOT / "workflows/workflow-analytics-host-prepare.yaml").read_text()
        )
        for step in lower["jobs"][0]["steps"]:
            self.assertEqual(
                step.pop("target"), '${{ "orbit-node-" + string(inputs.leaf.node_id) }}'
            )
            self.assertEqual(
                step.pop("labels"), {"node": "${{ string(inputs.leaf.node_id) }}"}
            )
        (self.repo / "workflows/workflow-analytics-host-prepare.yaml").write_text(
            yaml.safe_dump(lower, sort_keys=False)
        )
        parent = yaml.safe_load(
            (
                ROOT / "workflows/workflow-operation-analytics-enable-direct.yaml"
            ).read_text()
        )
        request_job = next(job for job in parent["jobs"] if job["name"] == "requests")
        request_job["dependsOn"] = [
            {"job": "prepare", "condition": {"type": "succeeded"}}
        ]
        for step in request_job["steps"]:
            self.assertEqual(
                step.pop("target"), '${{ "orbit-node-" + string(self.item.node_id) }}'
            )
            self.assertEqual(
                step.pop("labels"), {"node": "${{ string(self.item.node_id) }}"}
            )
        fixture = analytics / "native-plan.ts"
        fixture.write_text("""import {z} from "npm:zod@4.4.3";
export const behavior={type:"@fixture/analytics-host-plan",version:"2026.10.31.1",
resources:{plan:{description:"Public test plan",
schema:z.object({tracking:z.object({
certificates:z.array(z.record(z.string(),z.unknown()))})}),
lifetime:"infinite",garbageCollection:1}},
methods:{begin:{description:"Emit fixture public input",
arguments:z.object({leaves:z.array(z.record(z.string(),z.unknown()))}),
execute:async(args:{leaves:Record<string,unknown>[]},
ctx:{writeResource(spec:string,name:string,value:unknown):Promise<unknown>})=>{
const plan={tracking:{certificates:args.leaves}};
await ctx.writeResource("plan","plan",plan);return plan;}}}};
""")
        parent["id"] = "5bd92dd1-6c4e-4422-bc54-f25ed9b90c69"
        parent["name"] = "analytics-host-parent-fixture"
        parent["inputs"] = {
            "type": "object",
            "properties": {
                "operation_id": {"type": "integer"},
                "node_id": {"type": "integer"},
                "kind": {"type": "string"},
                "leaves": {"type": "array"},
            },
            "required": ["operation_id", "node_id", "kind", "leaves"],
        }
        parent["jobs"] = [
            {
                "name": "prepare",
                "steps": [
                    {
                        "name": "begin",
                        "task": {
                            "type": "behavior",
                            "behaviorType": "@fixture/analytics-host-plan",
                            "behaviorName": "fixture-analytics-plan",
                            "methodName": "begin",
                            "inputs": {"leaves": "${{ inputs.leaves }}"},
                        },
                    }
                ],
            },
            request_job,
            {
                "name": "check",
                "dependsOn": [{"job": "requests", "condition": {"type": "succeeded"}}],
                "steps": [
                    {
                        "name": "check",
                        "task": {
                            "type": "assert",
                            "message": "Each leaf must yield its public request",
                            "expr": (
                                'size(steps.filter(name, name.startsWith("request-"))) '
                                "== size(inputs.leaves) && steps.filter(name, "
                                'name.startsWith("request-")).all(name, '
                                "steps[name].outputs.prepared.node_id == 9 && "
                                'steps[name].outputs.prepared.scope.startsWith("route-"))'
                            ),
                        },
                    }
                ],
            },
        ]
        (
            self.repo / "workflows/workflow-analytics-host-parent-fixture.yaml"
        ).write_text(yaml.safe_dump(parent, sort_keys=False))
        self.input_file = self.repo / "inputs.json"

    def run_parent(self, success=True):
        self.input_file.write_text(json.dumps(self.inputs))
        result = self.cli(
            "workflow",
            "run",
            "analytics-host-parent-fixture",
            "--input-file",
            str(self.input_file),
            success=success,
        )
        run = json.JSONDecoder().raw_decode(result.stdout.lstrip())[0]
        history = self.cli("workflow", "history", "get", run["id"])
        return json.JSONDecoder().raw_decode(history.stdout.lstrip())[0]

    def candidate(self, leaf=None):
        leaf = leaf or self.leaf
        return (
            self.home
            / ".orbit/certificates"
            / leaf["scope"]
            / "versions"
            / (leaf["version"] + ".candidate")
        )

    def assert_key_private(self, key):
        self.assertEqual(key.stat().st_mode & 0o777, 0o600)
        self.assertEqual(key.stat().st_uid, os.getuid())
        key_bytes = key.read_bytes().splitlines()[1]
        for path in (self.repo / ".swamp").rglob("*"):
            if path.is_file() and not path.is_symlink():
                self.assertFalse(
                    key_bytes in path.read_bytes(), "Leaf key reached native artifacts"
                )

    def test_actual_parent_fanout_delivers_two_public_csrs_without_private_keys(self):
        self.inputs["leaves"].append(
            {**self.leaf, "route_id": 43, "scope": "route-43-router"}
        )
        self.cli("workflow", "validate", "analytics-host-prepare")
        run = self.run_parent()
        self.assertEqual(run["status"], "succeeded")
        requests = next(job for job in run["jobs"] if job["name"] == "requests")[
            "steps"
        ]
        for step, leaf in zip(requests, self.inputs["leaves"]):
            record = step["outputs"]["prepared"]
            self.assertEqual(record["scope"], leaf["scope"])
            self.assertEqual(record["state"], "request")
            public = self.repo / (leaf["scope"] + ".csr")
            public.write_bytes(base64.b64decode(record["request_base64"]))
            subprocess.run(
                ["openssl", "req", "-in", str(public), "-verify", "-noout"],
                check=True,
                capture_output=True,
            )
            self.assert_key_private(self.candidate(leaf) / "key.pem")

    def test_repeated_native_request_reuses_the_exact_candidate_key(self):
        self.run_parent()
        key = self.candidate() / "key.pem"
        before = key.read_bytes()
        self.run_parent()
        self.assertTrue(
            before == key.read_bytes(), "A repeated request replaced its candidate key"
        )
        self.assert_key_private(key)

    def test_a_valid_current_leaf_returns_no_csr_and_keeps_its_private_key(self):
        first = self.run_parent()
        prepared = next(job for job in first["jobs"] if job["name"] == "requests")[
            "steps"
        ][0]["outputs"]["prepared"]
        csr = self.repo / "current-request.csr"
        csr.write_bytes(base64.b64decode(prepared["request_base64"]))
        candidate = self.candidate()
        private_key = (candidate / "key.pem").read_bytes()
        ca_key = self.repo / "disposable-ca.key"
        subprocess.run(
            [
                "openssl",
                "req",
                "-x509",
                "-newkey",
                "rsa:2048",
                "-nodes",
                "-keyout",
                str(ca_key),
                "-out",
                str(self.ca),
                "-subj",
                "/CN=Disposable test CA",
                "-days",
                "400",
            ],
            check=True,
            capture_output=True,
        )
        self.leaf["root_hash"] = hashlib.sha256(self.ca.read_bytes()).hexdigest()
        self.inputs["operation_id"] = 8
        extensions = self.repo / "leaf.ext"
        extensions.write_text(
            "basicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature,keyEncipherment\n"
            "extendedKeyUsage=serverAuth\nsubjectAltName=DNS:analytics.example.test\n"
        )
        certificate = self.repo / "public-leaf.pem"
        subprocess.run(
            [
                "openssl",
                "x509",
                "-req",
                "-in",
                str(csr),
                "-CA",
                str(self.ca),
                "-CAkey",
                str(ca_key),
                "-CAcreateserial",
                "-days",
                "398",
                "-extfile",
                str(extensions),
                "-out",
                str(certificate),
            ],
            check=True,
            capture_output=True,
        )
        current = candidate.parent.parent / "current"
        current.mkdir()
        shutil.copy(candidate / "key.pem", current / "key.pem")
        shutil.copy(certificate, current / "cert.pem")
        shutil.copy(self.ca, current / "root.pem")
        published = self.caddy / self.leaf["scope"] / "current"
        published.mkdir(parents=True)
        shutil.copy(current / "key.pem", published / "key.pem")
        shutil.copy(certificate, published / "cert.pem")
        run = self.run_parent()
        request = next(job for job in run["jobs"] if job["name"] == "requests")[
            "steps"
        ][0]
        self.assertEqual(
            request["outputs"]["prepared"],
            {"node_id": 9, "scope": self.leaf["scope"], "state": "current"},
        )
        self.assertTrue(
            private_key == (current / "key.pem").read_bytes(), "Current key changed"
        )
        self.assert_key_private(candidate / "key.pem")

    def test_foreign_route_scope_refuses_before_any_key_creation(self):
        self.inputs["leaves"][0] = {**self.leaf, "scope": "route-43-router"}
        self.run_parent(success=False)
        self.assertFalse((self.home / ".orbit").exists())

    def test_foreign_home_refuses_before_any_key_creation(self):
        self.inputs["leaves"][0] = {
            **self.leaf,
            "home": str(self.repo / "foreign-home"),
        }
        self.run_parent(success=False)
        self.assertFalse((self.home / ".orbit").exists())

    def test_empty_admitted_fanout_has_no_certificate_effect(self):
        self.inputs["leaves"] = []
        self.assertEqual(self.run_parent()["status"], "succeeded")
        self.assertFalse((self.home / ".orbit").exists())


if __name__ == "__main__":
    unittest.main()
