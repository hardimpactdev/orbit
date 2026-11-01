"""Keep legacy residue withdrawal parity on the current native certificate children."""

import importlib.util
import json
import shutil
import sys
from pathlib import Path

import yaml

HERE = Path(__file__).parent
SPEC = importlib.util.spec_from_file_location(
    "certificate_fixture", HERE / "native_fixture.py"
)
FIXTURE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(FIXTURE)


class ResidueCertificates(FIXTURE.Certificates):
    def setUp(self):
        super().setUp()
        self.copy_behaviors("operations/routes/mutation-command")
        publication = (
            self.repo / "extensions/behaviors/operations/routes/publication-command"
        )
        publication.mkdir()
        shutil.copy(
            FIXTURE.ROOT
            / "extensions/behaviors/operations/routes/publication-command/sections.ts",
            publication,
        )
        for kind, name in [
            ("converge", "route-certificate-publish"),
            ("remove", "route-certificate-remove"),
        ]:
            flow = yaml.safe_load(
                (FIXTURE.ROOT / ("workflows/workflow-" + name + ".yaml")).read_text()
            )
            for job in flow["jobs"]:
                for step in job["steps"]:
                    self.assertEqual(step.pop("target"), "orbit-node-209")
                    self.assertNotIn("labels", step)
                    task = step["task"]
                    if task.get("behaviorType") == "@swamp/ssh":
                        task["globalArgs"].update(
                            sshBinary=str(self.tools / "ssh"),
                            scpBinary=str(self.tools / "scp"),
                        )
            if kind == "remove":
                steps = flow["jobs"][0]["steps"]
                globals_ = steps[1]["task"]["globalArgs"]
                # The residue parent reloads its replacement configuration before the removal child.
                # Keep the original F9 failure expectation visible in this adapter.
                steps[0]["dependsOn"] = [
                    {"step": "reload-withdrawal", "condition": {"type": "succeeded"}}
                ]
                steps.insert(
                    0,
                    {
                        "name": "reload-withdrawal",
                        "task": {
                            "type": "behavior",
                            "behaviorType": "@swamp/ssh",
                            "behaviorName": "fixture-withdrawal-reload",
                            "methodName": "exec",
                            "globalArgs": globals_,
                            "inputs": {
                                "hosts": "name:target",
                                "argv": ["systemctl", "try-reload-or-restart", "caddy"],
                            },
                        },
                    },
                )
            (self.repo / ("workflows/workflow-" + name + ".yaml")).write_text(
                yaml.safe_dump(flow, sort_keys=False).replace(
                    "/tmp/orbit-route-", str(self.fixture / "staging-")
                )
            )
        scp = self.tools / "scp"
        scp.write_text(
            "#!" + sys.executable + "\nimport shutil,sys\nfrom pathlib import Path\n"
            "src=Path(sys.argv[-2].split(':',1)[-1]);dst=Path(sys.argv[-1].split(':',1)[-1])\n"
            "if src.is_dir():shutil.copytree(src,dst/src.name,dirs_exist_ok=True)\n"
            "else:shutil.copy2(src,dst)\n"
        )
        (self.fixture / "identity").write_text("synthetic fixture only")

    def run_workflow(self, kind="converge", success=True):
        leaf = (
            self.leaf
            if kind == "converge"
            else {
                **{
                    k: self.leaf[k]
                    for k in ("node_id", "scope", "user", "agent_sha256")
                },
                "in_use": False,
            }
        )
        intent = {
            "control_node_id": 209,
            "host_keys": [
                "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIEV4aXN0aW5nUGlubmVkSG9zdElkZW50aXR5UHVibGlj"
            ],
            "connection": {
                "nodeHost": "fixture",
                "nodePort": 22,
                "nodeUser": "orbit",
                "nodeIdentityFile": str(self.fixture / "identity"),
            },
            **(
                {"node_id": 9, "leaves": [leaf]}
                if kind == "converge"
                else {"leaf": leaf}
            ),
        }
        name = (
            "route-certificate-publish"
            if kind == "converge"
            else "route-certificate-remove"
        )
        self.cli("workflow", "validate", name)
        return json.loads(
            self.cli(
                "workflow",
                "run",
                name,
                "--input",
                json.dumps(
                    {
                        "operation_id": 19,
                        "node_id": 9,
                        "kind": "node.role_add",
                        "parent_run_id": "residue-parent-run",
                        "restoring": False,
                        "intent": intent,
                        "execution_secrets": {},
                    }
                ),
                success=success,
            ).stdout
        )

    def test_removal_preserves_serving_certificates_until_configuration_is_withdrawn(
        self,
    ):
        self.assertEqual("succeeded", self.run_workflow()["status"])
        root = self.home / ".orbit/certificates/route-7-router"
        caddy = self.fixture / "caddy/route-7-router"
        key = (root / "current/key.pem").read_bytes()
        configuration = self.fixture / "caddy-adapt.json"
        (self.tools / "caddy").write_text(
            "#!"
            + sys.executable
            + "\nfrom pathlib import Path\n"
            + f"print(Path({str(configuration)!r}).read_text())\n"
        )
        for certificates in (
            {"load_files": [{"certificate": str(caddy / "current/cert.pem")}]},
            {"load_folders": [str(caddy.parent)]},
        ):
            with self.subTest(certificates=certificates):
                configuration.write_text(
                    json.dumps({"apps": {"tls": {"certificates": certificates}}})
                )
                failed = self.run_workflow("remove", success=False)
                steps = {
                    step["name"]: step["status"] for step in failed["jobs"][0]["steps"]
                }
                self.assertEqual("failed", steps["remove"])
                self.assertEqual("skipped", steps["inspect"])
                self.assertEqual(key, (root / "current/key.pem").read_bytes())
                self.assertTrue((caddy / "current/cert.pem").is_file())

        configuration.write_text('{"apps":{}}')
        (self.tools / "systemctl").write_text(
            '#!/bin/sh\nif [ "$1" = is-active ]; then exit 0; fi\nexit 1\n'
        )
        failed = self.run_workflow("remove", success=False)
        steps = {step["name"]: step["status"] for step in failed["jobs"][0]["steps"]}
        self.assertEqual("failed", steps["reload-withdrawal"])
        self.assertEqual("skipped", steps["remove"])
        self.assertEqual(key, (root / "current/key.pem").read_bytes())

        (self.tools / "systemctl").write_text("#!/bin/sh\nexit 0\n")
        self.assertEqual("succeeded", self.run_workflow("remove")["status"])
        self.assertFalse(root.exists())
        self.assertFalse(caddy.exists())
        self.assertEqual(1, len(self.signs))
