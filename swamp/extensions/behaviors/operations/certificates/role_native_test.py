"""Fixed-role child through official SSH and the canonical HTTPS signer."""

import importlib.util
import json
import os
import shutil
import tempfile
from pathlib import Path

import yaml

HERE = Path(__file__).parent
spec = importlib.util.spec_from_file_location(
    "certificate_native", HERE / "native_fixture.py"
)
fixture = importlib.util.module_from_spec(spec)
spec.loader.exec_module(fixture)


class Roles(fixture.Certificates):
    def setUp(self):
        super().setUp()
        for name in ("role_assets.ts", "role_source.ts"):
            shutil.copy(
                HERE / name,
                self.repo / "extensions/behaviors/operations/certificates" / name,
            )
        target = tempfile.TemporaryDirectory(
            prefix="orbit-role-target-", dir=os.environ.get("TMPDIR")
        )
        self.addCleanup(target.cleanup)
        self.target = Path(target.name)
        scripts = self.repo / "extensions/behaviors/operations/certificates/scripts"
        paths = scripts / "role_paths.py"
        paths.write_text(
            paths.read_text()
            .replace(
                'ROOT = Path("/etc/caddy")',
                f"ROOT = Path({str(self.target / 'caddy')!r})",
            )
            .replace(
                'KEY_ROOT = Path("/var/lib/orbit/role-certificates")',
                f"KEY_ROOT = Path({str(self.target / 'role-keys')!r})",
            )
        )
        publish = scripts / "role_publish.py"
        publish.write_text(
            publish.read_text().replace('grp.getgrnam("caddy").gr_gid', "os.getgid()")
        )
        (self.target / "caddy").mkdir()
        fake = self.tools / "ssh"
        source = fake.read_text()
        source = source.replace(
            "result=subprocess.run(argv,input=sys.stdin.buffer.read(),capture_output=True)",
            """
if argv[:2] == ['/usr/bin/systemctl','is-active']:
    raise SystemExit(0)
if argv[:2] == ['/usr/bin/systemctl','try-reload-or-restart']:
    if Path(INACTIVE).exists(): raise SystemExit(0)
    with open(ACTIVATIONS,'a') as stream: stream.write('activate\\n')
    failure=Path(FAIL_ACTIVATE)
    if failure.exists(): failure.unlink();raise SystemExit(1)
    raise SystemExit(0)
result=subprocess.run(argv,input=sys.stdin.buffer.read(),capture_output=True)
""".replace("ACTIVATIONS", repr(str(self.fixture / "activations")))
            .replace("INACTIVE", repr(str(self.fixture / "inactive")))
            .replace("FAIL_ACTIVATE", repr(str(self.fixture / "fail-activate"))),
        )
        fake.write_text(source)
        child = yaml.safe_load(
            (
                fixture.ROOT / "workflows/workflow-node-role-certificate-publish.yaml"
            ).read_text()
        )
        for step in child["jobs"][0]["steps"]:
            self.assertEqual(step.pop("target"), "orbit-node-209")
            self.assertNotIn("labels", step)
            task = step["task"]
            if task.get("behaviorType") == "@swamp/ssh":
                task["globalArgs"].update(
                    sshBinary=str(fake), scpBinary=str(self.tools / "scp")
                )
        (
            self.repo / "workflows/workflow-node-role-certificate-publish.yaml"
        ).write_text(
            yaml.safe_dump(child, sort_keys=False).replace(
                "/tmp/orbit-role-certificate-", str(self.fixture / "role-staging-")
            )
        )
        self.globals = {
            "name": "role-fixture",
            "sshBinary": str(fake),
            "transport": {
                "kind": "ssh",
                "user": "orbit",
                "identityFile": str(self.fixture / "identity"),
            },
            "hosts": [
                {
                    "name": "target",
                    "address": "fixture",
                    "transport": {
                        "hostKeys": [
                            "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIEV4aXN0aW5nUGlubmVkSG9zdElkZW50aXR5UHVibGlj"
                        ]
                    },
                }
            ],
        }
        (self.fixture / "identity").write_text("synthetic identity never used")
        self.leaf = {
            k: v
            for k, v in self.leaf.items()
            if k in ("node_id", "version", "root_hash")
        }
        self.leaf.update(scope="role-metrics", domain="shop.test")
        self.current = self.target / "caddy/orbit-metrics-cert-current"

    def run_role(self, success=True, **changes):
        self.cli("workflow", "validate", "node-role-certificate-publish")
        leaf = {**self.leaf, **changes}
        return json.loads(
            self.cli(
                "workflow",
                "run",
                "node-role-certificate-publish",
                "--input",
                "operation_id=19",
                "--input",
                "node_id=9",
                "--input",
                "kind=node.role_converge",
                "--input",
                "parent_run_id=actual-root-run",
                "--input",
                "leaf=" + json.dumps(leaf),
                "--input",
                "ssh=" + json.dumps(self.globals),
                success=success,
            ).stdout
        )

    def test_role_fresh_current_and_actual_parent_signing(self):
        result = self.run_role()
        self.assertEqual("succeeded", result["status"])
        self.assertEqual("actual-root-run", self.signs[0][1]["run_id"])
        self.assertTrue((self.current / "metrics.key").is_file())
        key = (self.current / "metrics.key").read_bytes()
        self.secrets += (key.decode(),)
        self.assert_private_absent(json.dumps(result))
        self.secrets = self.secrets[:-1]
        self.assertEqual("succeeded", self.run_role()["status"])
        self.assertEqual(1, len(self.signs))
        self.assertEqual(key, (self.current / "metrics.key").read_bytes())

    def test_actual_parent_composes_child_and_receives_flat_readiness(self):
        import subprocess

        factory = "import {roleCertificateSteps} from './extensions/behaviors/operations/certificates/role_steps.ts';console.log(JSON.stringify(roleCertificateSteps('cert','inputs.leaf',{name:'${{ inputs.ssh.name }}',transport:'${{ inputs.ssh.transport }}',hosts:'${{ inputs.ssh.hosts }}'})));"
        output = subprocess.run(
            [os.environ.get("DENO_BIN", "/tmp/lane4-deno-2.9.8/deno"), "eval", factory],
            cwd=fixture.ROOT,
            text=True,
            capture_output=True,
            check=True,
        )
        steps = json.loads(output.stdout)
        for step in steps:
            step.pop("target")
            step.pop("labels", None)
        steps.append(
            {
                "name": "verify",
                "dependsOn": [{"step": "cert", "condition": {"type": "succeeded"}}],
                "task": {
                    "type": "assert",
                    "expr": 'steps.cert.outputs.result.ready && steps.cert.outputs.result.node_id == 9 && steps.cert.outputs.result.scope == "role-metrics"',
                    "message": "role child readiness",
                    "severity": "high",
                },
            }
        )
        parent = {
            "id": "2a354750-5e1f-4a68-a950-bf8050ae3759",
            "name": "role-parent-fixture",
            "inputs": {
                "type": "object",
                "properties": {
                    "operation_id": {"type": "integer"},
                    "node_id": {"type": "integer"},
                    "kind": {"type": "string"},
                    "leaf": {"type": "object"},
                    "ssh": {"type": "object"},
                },
            },
            "jobs": [{"name": "parent", "steps": steps}],
        }
        (self.repo / "workflows/workflow-role-parent-fixture.yaml").write_text(
            yaml.safe_dump(parent, sort_keys=False)
        )
        result = json.loads(
            self.cli(
                "workflow",
                "run",
                "role-parent-fixture",
                "--input",
                "operation_id=19",
                "--input",
                "node_id=9",
                "--input",
                "kind=node.role_converge",
                "--input",
                "leaf=" + json.dumps(self.leaf),
                "--input",
                "ssh=" + json.dumps(self.globals),
            ).stdout
        )
        self.assertEqual("succeeded", result["status"])
        self.assertEqual(result["id"], self.signs[0][1]["run_id"])

    def fail_activation(self):
        self.run_role()
        # Force a new pair under the same domain and canonical stable path.
        root = self.current / "root-ca.pem"
        root.write_bytes(root.read_bytes() + b"\n")
        previous = (self.current / "metrics.key").read_bytes()
        (self.fixture / "fail-activate").touch()
        result = self.run_role(success=False, version="fedcba9876543210")
        self.assertEqual("failed", result["status"])
        self.assertNotEqual(previous, (self.current / "metrics.key").read_bytes())
        states = {s["name"]: s["status"] for s in result["jobs"][0]["steps"]}
        self.assertEqual("failed", states["activate"])
        self.assertNotEqual("succeeded", states["result"])
        self.assertNotIn("restore", states)
        self.assertNotEqual("succeeded", states["cleanup-key"])
        pending = self.target / "role-keys" / self.leaf["scope"] / "fedcba9876543210"
        self.assertTrue((pending / "key.pem").is_file())
        self.assertTrue((pending / "cert.pem").is_file())
        return result

    def test_failed_activation_stays_failed_and_native_resume_retries_only_activation(
        self,
    ):
        result = self.fail_activation()
        pair = {p.name: p.read_bytes() for p in self.current.iterdir()}
        resumed = json.loads(
            self.cli(
                "workflow",
                "resume",
                "node-role-certificate-publish",
                "--run",
                result["id"],
                "--from",
                "activate",
            ).stdout
        )
        self.assertEqual("succeeded", resumed["status"])
        self.assertEqual(2, len(self.signs))
        self.assertFalse(
            (
                self.target / "role-keys" / self.leaf["scope"] / "fedcba9876543210"
            ).exists()
        )
        self.assertEqual(pair, {p.name: p.read_bytes() for p in self.current.iterdir()})

    def test_fresh_admission_activates_current_pair_after_interrupted_activation(self):
        self.fail_activation()
        before = (self.fixture / "activations").read_text()
        self.assertEqual(
            "succeeded", self.run_role(version="1111111111111111")["status"]
        )
        self.assertEqual(2, len(self.signs))
        self.assertNotEqual(before, (self.fixture / "activations").read_text())

    def test_inactive_caddy_is_not_started_by_certificate_publication(self):
        (self.fixture / "inactive").touch()
        self.assertEqual("succeeded", self.run_role()["status"])
        self.assertFalse((self.fixture / "activations").exists())
        self.assertTrue((self.current / "metrics.key").is_file())

    def test_role_signing_refusal_and_missing_pins_prevent_publication(self):
        self.refuse = True
        self.assertEqual("failed", self.run_role(success=False)["status"])
        self.assertFalse(self.current.exists())
        self.globals["hosts"][0]["transport"]["hostKeys"] = []
        self.run_role(success=False)
        self.assertFalse(self.current.exists())


if __name__ == "__main__":
    import unittest

    unittest.main()
