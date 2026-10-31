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
    "certificate_native", HERE / "native_test.py"
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
        policy = (
            self.repo
            / "extensions/behaviors/operations/certificates/scripts/role_policy.py"
        )
        source = policy.read_text().replace(
            'ROOT = Path("/etc/caddy")', f"ROOT = Path({str(self.target / 'caddy')!r})"
        )
        source = source.replace(
            'KEY_ROOT = Path("/var/lib/orbit/role-certificates")',
            f"KEY_ROOT = Path({str(self.target / 'role-keys')!r})",
        )
        source = source.replace("OWNER_UID = 0", "OWNER_UID = os.getuid()")
        source = source.replace(
            "# END HOST",
            '\nos.chown = lambda *args: None\nos.fchown = lambda *args: None\ngrp.getgrnam = lambda _: __import__("types").SimpleNamespace(gr_gid=os.getgid())\n# END HOST',
        )
        policy.write_text(source)
        (self.target / "caddy").mkdir()
        fake = self.tools / "ssh"
        source = fake.read_text()
        source = source.replace(
            "result=subprocess.run(argv,input=sys.stdin.buffer.read(),capture_output=True)",
            """
if argv[:2] == ['/usr/bin/systemctl','is-active']:
    raise SystemExit(0)
if argv[:2] == ['/usr/bin/systemctl','reload-or-restart']:
    with open(ACTIVATIONS,'a') as stream: stream.write('activate\\n')
    failure=Path(FAIL_ACTIVATE)
    if failure.exists(): failure.unlink();raise SystemExit(1)
    raise SystemExit(0)
result=subprocess.run(argv,input=sys.stdin.buffer.read(),capture_output=True)
""".replace("ACTIVATIONS", repr(str(self.fixture / "activations"))).replace(
                "FAIL_ACTIVATE", repr(str(self.fixture / "fail-activate"))
            ),
        )
        fake.write_text(source)
        child = yaml.safe_load(
            (
                fixture.ROOT / "workflows/workflow-node-role-certificate-publish.yaml"
            ).read_text()
        )
        for step in child["jobs"][0]["steps"]:
            self.assertEqual(step.pop("target"), "orbit-node-209")
            self.assertEqual(step.pop("labels"), {"node": "209"})
            task = step["task"]
            if task.get("behaviorType") == "@swamp/ssh":
                task["globalArgs"]["sshBinary"] = str(fake)
            if task.get("behaviorType") == "command/shell":
                # The fixture signer and its synthetic CA stay outside the native repository.
                original = next(
                    s
                    for s in yaml.safe_load(
                        (
                            self.repo
                            / "workflows/workflow-operation-certificate-converge.yaml"
                        ).read_text()
                    )["jobs"][0]["steps"]
                    if s["task"].get("behaviorType") == "command/shell"
                )
                task["inputs"]["run"] = original["task"]["inputs"]["run"]
        (
            self.repo / "workflows/workflow-node-role-certificate-publish.yaml"
        ).write_text(yaml.safe_dump(child, sort_keys=False))
        self.globals = dict(
            name="role-fixture",
            sshBinary=str(fake),
            transport=dict(
                kind="ssh", user="orbit", identityFile=str(self.fixture / "identity")
            ),
            hosts=[
                dict(
                    name="target",
                    address="fixture",
                    transport=dict(
                        hostKeys=[
                            "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIEV4aXN0aW5nUGlubmVkSG9zdElkZW50aXR5UHVibGlj"
                        ]
                    ),
                )
            ],
        )
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
            step.pop("labels")
        steps.append(
            dict(
                name="verify",
                dependsOn=[dict(step="cert", condition=dict(type="succeeded"))],
                task=dict(
                    type="assert",
                    expr='steps.cert.outputs.result.ready && steps.cert.outputs.result.node_id == 9 && steps.cert.outputs.result.scope == "role-metrics"',
                    message="role child readiness",
                    severity="high",
                ),
            )
        )
        parent = dict(
            id="2a354750-5e1f-4a68-a950-bf8050ae3759",
            name="role-parent-fixture",
            inputs=dict(
                type="object",
                properties=dict(
                    operation_id=dict(type="integer"),
                    node_id=dict(type="integer"),
                    kind=dict(type="string"),
                    leaf=dict(type="object"),
                    ssh=dict(type="object"),
                ),
            ),
            jobs=[dict(name="parent", steps=steps)],
        )
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

    def test_activation_failure_restores_prior_pair_and_does_not_claim_ready(self):
        self.run_role()
        root = self.current / "root-ca.pem"
        root.write_bytes(root.read_bytes() + b"\n")
        previous = {p.name: p.read_bytes() for p in self.current.iterdir()}
        (self.fixture / "fail-activate").touch()
        result = self.run_role(success=False, version="fedcba9876543210")
        self.assertEqual("failed", result["status"])
        self.assertEqual(
            previous, {p.name: p.read_bytes() for p in self.current.iterdir()}
        )
        states = {s["name"]: s["status"] for s in result["jobs"][0]["steps"]}
        self.assertEqual("succeeded", states["restore"])
        self.assertEqual("succeeded", states["reactivate"])
        self.assertNotEqual("succeeded", states["result"])

        resumed = json.loads(
            self.cli(
                "workflow",
                "resume",
                "node-role-certificate-publish",
                "--run",
                result["id"],
                "--from",
                "publish",
            ).stdout
        )
        self.assertEqual("succeeded", resumed["status"])
        self.assertEqual(2, len(self.signs))
        self.assertNotEqual(
            previous["metrics.key"], (self.current / "metrics.key").read_bytes()
        )

    def test_pending_pair_fresh_admission_does_not_skip_activation(self):
        import importlib.util

        policy = (
            self.repo
            / "extensions/behaviors/operations/certificates/scripts/role_policy.py"
        )
        module_spec = importlib.util.spec_from_file_location(
            "role_policy_fixture", policy
        )
        module = importlib.util.module_from_spec(module_spec)
        module_spec.loader.exec_module(module)
        # A prior process stopped after publication but before activation.
        self.run_role()
        saved = module.role_previous(self.leaf)
        saved.mkdir(mode=0o700)
        workspace = module.KEY_ROOT / self.leaf["scope"] / self.leaf["version"]
        workspace.mkdir(parents=True, mode=0o700)
        (workspace / "key.pem").write_bytes((self.current / "metrics.key").read_bytes())
        (workspace / "key.pem").chmod(0o600)
        # Parent directories mirror the existing private-key workspace policy.
        workspace.parent.chmod(0o700)
        module.KEY_ROOT.chmod(0o700)
        before = (self.fixture / "activations").read_text()
        self.assertEqual(
            "succeeded", self.run_role(version="fedcba9876543210")["status"]
        )
        self.assertNotEqual(before, (self.fixture / "activations").read_text())
        self.assertFalse(saved.exists())

    def test_role_signing_refusal_and_missing_pins_prevent_publication(self):
        self.refuse = True
        self.assertEqual("failed", self.run_role(success=False)["status"])
        self.assertFalse(self.current.exists())
        self.globals["hosts"][0]["transport"]["hostKeys"] = []
        self.run_role(success=False)
        self.assertFalse(self.current.exists())

    # Only the role child is exercised by this derived fixture.
    test_fresh_current_and_removal_use_official_ssh_and_one_parent_signer = None
    test_missing_pins_refuse_before_any_remote_action = None
    test_signer_refusal_makes_no_publication_or_hidden_callback = None
    test_native_inspection_resume_preserves_the_key_without_signing_again = None


if __name__ == "__main__":
    import unittest

    unittest.main()
