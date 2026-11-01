"""Owning role parents on native Swamp with official SSH and disposable host seams."""

import copy
import json
import os
import shutil
from pathlib import Path

import node_database_role_native_test as database
import yaml
from native_harness import ROOT


class RoleParentNative(database.DatabaseRoleNative):
    native_timeout = 180

    def setUp(self):
        super().setUp()
        self.copy_behaviors(
            "operations/node-role-parent",
            "operations/node-removal-command",
            "operations/routes/residue-command",
        )
        certificates = (
            self.repo / "extensions/behaviors/operations/certificates/scripts"
        )
        certificates.mkdir(parents=True)
        for script in ["role_paths.py", "remove.py", "local.py"]:
            shutil.copy(
                ROOT / "extensions/behaviors/operations/certificates/scripts" / script,
                certificates,
            )
        for script in ["php-shared-program.ts", "firewall-program.ts"]:
            shutil.copy(
                ROOT / "extensions/behaviors/operations/routes" / script,
                self.repo / "extensions/behaviors/operations/routes",
            )
        shutil.copytree(
            ROOT / "extensions/behaviors/operations/routes/mutation-command",
            self.repo / "extensions/behaviors/operations/routes/mutation-command",
        )
        policy = (
            self.repo / "extensions/behaviors/operations/routes/publication-command"
        )
        policy.mkdir()
        shutil.copy(
            ROOT
            / "extensions/behaviors/operations/routes/publication-command/sections.ts",
            policy,
        )
        for name in [
            "operation-route-caddy-delta",
            "operation-route-php-delta",
            "route-dns-publish",
        ]:
            flow = yaml.safe_load(
                (ROOT / ("workflows/workflow-" + name + ".yaml")).read_text()
            )
            flow.pop("target", None)
            flow["jobs"] = [
                {
                    "name": "boundary",
                    "steps": [
                        {
                            "name": "result",
                            "task": {
                                "type": "assert",
                                "expr": "true",
                                "message": "Unselected or separately tested shared child",
                            },
                        }
                    ],
                }
            ]
            (self.repo / ("workflows/workflow-" + name + ".yaml")).write_text(
                yaml.safe_dump(flow, sort_keys=False)
            )
        for name in [
            "operation-node-role-add-direct",
            "operation-node-role-relocate-direct",
            "node-role-remove",
            "route-dns-grace",
            "route-residue",
        ]:
            flow = yaml.safe_load(
                (ROOT / ("workflows/workflow-" + name + ".yaml")).read_text()
            )
            flow.pop("target", None)
            if name in [
                "operation-node-role-add-direct",
                "operation-node-role-relocate-direct",
            ]:
                # Preserve the full dispatcher graph. Unselected retained effects
                # fail if called; the real legacy effects keep their own parity tests.
                legacy = [
                    job
                    for job in flow["jobs"]
                    if job["name"] not in ["target", "source", "rollback", "result"]
                    and not job["name"].startswith("forward_")
                ]
                self.assertTrue(legacy)
                for job in legacy:
                    for step in job["steps"]:
                        self.assertTrue(
                            step["guard"].startswith(
                                "${{ (has(inputs.intent) && inputs.intent != null) ||"
                            )
                        )
                        step["task"] = {
                            "type": "assert",
                            "expr": "false",
                            "severity": "high",
                            "message": "A native intent selected retained role work",
                        }
                self.retained_jobs = {job["name"] for job in legacy}
            for job in flow["jobs"]:
                job.pop("target", None)
                for step in job["steps"]:
                    placement = step.pop("target")
                    if "has(inputs.intent)" not in step.get(
                        "guard", ""
                    ) or not step.get("guard", "").startswith(
                        "${{ (has(inputs.intent)"
                    ):
                        self.assertEqual(placement, "orbit-node-209")
                    self.assertNotIn("labels", step)
                    task = step["task"]
                    if task.get("behaviorType") == "@swamp/ssh":
                        task["globalArgs"]["sshBinary"] = str(self.repo / "fixture-ssh")
                        task["globalArgs"]["scpBinary"] = str(self.repo / "fixture-scp")
            (self.repo / ("workflows/workflow-" + name + ".yaml")).write_text(
                yaml.safe_dump(flow, sort_keys=False)
            )
        self.input["kind"] = "node.role_add"
        self.input["intent"] = {"target": self.input["intent"]}
        self.input["intent"]["target"]["created"] = True
        binary = self.repo / "fixture-ssh"
        binary.write_text(
            binary.read_text()
            .replace(
                "if phase=='account.py':",
                "if phase=='residue.py':finish('residue',{'changed':True})\n if phase=='account.py':",
            )
            .replace(
                "if not body:",
                "if 'REMOVAL_INPUT' in body:finish('remove_runtime',{})\nif not body:",
            )
        )
        self.timings = []

    def run_parent(self, success=True):
        name = (
            "operation-node-role-relocate-direct"
            if self.input["kind"] == "node.role_relocate"
            else "operation-node-role-add-direct"
        )
        self.cli("workflow", "validate", name)
        run = json.loads(
            self.cli(
                "workflow",
                "run",
                name,
                "--input",
                json.dumps(self.input),
                success=success,
            ).stdout
        )
        history = json.loads(self.cli("workflow", "history", "get", run["id"]).stdout)
        steps = {step["name"]: step for job in history["jobs"] for step in job["steps"]}
        for job in history["jobs"]:
            if job["name"] in self.retained_jobs and job["status"] != "skipped":
                for step in job["steps"]:
                    self.assertEqual(step["status"], "skipped", step)
        result = json.loads(steps["result"]["outputs"]["stdout"])
        evidence = os.environ.get("ROLE_PARENT_NATIVE_EVIDENCE")
        if evidence:
            destination = Path(evidence)
            destination.mkdir(parents=True, exist_ok=True)
            (destination / (name + "-" + run["id"] + ".json")).write_text(
                json.dumps(history, indent=2)
            )
        return history, result

    def test_add_success_and_failure_preserve_new_assignment_facts(self):
        for _ in range(3):
            history, result = self.run_parent()
            self.assertEqual(history["status"], "succeeded")
            self.assertTrue(result["ready"])
            self.assertTrue(result["target"]["created"])
            self.assertEqual(result["target"]["assignment_id"], 17)
        self.failure.write_text("packages")
        history, result = self.run_parent(False)
        self.assertEqual(history["status"], "failed")
        self.assertFalse(result["ready"])
        self.assertFalse(result["target_completed"])
        self.assertTrue(result["target"]["created"])

    def prepare_move(self):
        module = (
            self.repo
            / "extensions/behaviors/operations/node-role-converge-command/supplier_fixture.ts"
        )
        module.write_text(
            """import {z} from "npm:zod@4.4.3";
export const behavior={type:"@nckrtl/role-supplier-fixture",version:"2026.11.01.1",resources:{result:{schema:z.record(z.string(),z.unknown()),lifetime:"1d",garbageCollection:10}},methods:{complete:{description:"Return a typed supplier boundary fixture",arguments:z.object({action:z.string(),parent_run_id:z.string().optional()}),execute:async(args:{action:string;parent_run_id?:string},ctx:{writeResource(s:string,n:string,v:unknown):Promise<unknown>})=>{await Deno.writeTextFile(AUDIT,JSON.stringify(args)+"\\n",{append:true});const result={completed:true,ready:true,node_id:5,scope:"role-websocket",version:"cccccccccccccccc"};await ctx.writeResource("result","result",result);return result;}}}};
""".replace("AUDIT", json.dumps(str(self.audit)))
        )
        for name, action in [
            ("node-role-websocket", "runtime"),
            ("node-role-certificate-publish", "certificate"),
            ("node-role-publication", "publication"),
        ]:
            path = self.repo / ("workflows/workflow-" + name + ".yaml")
            flow = yaml.safe_load(path.read_text())
            args = {"action": action}
            if action == "certificate":
                args["parent_run_id"] = "${{ inputs.parent_run_id }}"
            flow["jobs"] = [
                {
                    "name": "fixture",
                    "steps": [
                        {
                            "name": "result",
                            "task": {
                                "type": "behavior",
                                "behaviorType": "@nckrtl/role-supplier-fixture",
                                "behaviorName": action + "-fixture",
                                "methodName": "complete",
                                "inputs": args,
                            },
                        }
                    ],
                }
            ]
            path.write_text(yaml.safe_dump(flow, sort_keys=False))
        self.input["kind"] = "node.role_relocate"
        target = self.input["intent"]["target"]
        target.update(
            role="websocket",
            created=False,
            runtime={
                "node_id": 5,
                "assignment_id": 17,
                "user": "orbit",
                "install_path": "/srv/reverb",
                "repository": "https://example.invalid/reverb.git",
                "ref": "b" * 40,
                "port": 8088,
            },
            caddy_package={
                "release": "2.11.1",
                "minimum_version": "2.10.2",
                "amd64_sha512": "a" * 128,
            },
            certificates={
                "leaves": [
                    {
                        "node_id": 5,
                        "scope": "role-websocket",
                        "domain": "reverb.orbit",
                        "version": "a" * 16,
                        "root_hash": "b" * 64,
                    }
                ],
                "withdrawals": [],
            },
            publications=[],
            firewall={
                "desired": [
                    [
                        "ufw",
                        "allow",
                        "in",
                        "on",
                        "orbit",
                        "to",
                        "10.44.0.5",
                        "comment",
                        "orbit:wireguard-members",
                    ]
                ],
                "remove": [],
            },
        )
        self.input["execution_secrets"]["runtime_material"] = copy.deepcopy(
            self.input["execution_secrets"]["observer"]
        )
        source = {
            "schema_version": 1,
            "node_id": 6,
            "assignment_id": 17,
            "role": "websocket",
            "ssh": target["ssh"],
            **{
                name: []
                for name in [
                    "publications",
                    "stop_units",
                    "remove_footprints",
                    "reload_units",
                    "remove_units",
                    "stop_containers",
                    "remove_containers",
                    "firewall",
                    "certificates",
                    "ci_host",
                ]
            },
        }
        self.input["intent"].update(source=source, withdrawal_grace_seconds=31)

    def test_relocate_observes_native_dns_grace_before_source_cleanup(self):
        self.prepare_move()
        history, result = self.run_parent()
        self.assertTrue(result["target_completed"], result)
        self.assertTrue(result["source_completed"], result)
        self.assertTrue(result["ready"], result)
        steps = {step["name"]: step for job in history["jobs"] for step in job["steps"]}
        self.assertEqual(steps["forward_source_grace"]["status"], "succeeded")
        self.assertEqual(steps["source_cleanup"]["status"], "succeeded")

    def test_new_websocket_runtime_is_removed_after_later_publication_failure(self):
        for created in [True, False]:
            with self.subTest(created=created):
                self.prepare_move()
                self.input["kind"] = "node.role_add"
                self.input["intent"]["target"]["created"] = created
                path = self.repo / "workflows/workflow-node-role-publication.yaml"
                flow = yaml.safe_load(path.read_text())
                flow["jobs"] = [
                    {
                        "name": "fixture",
                        "steps": [
                            {
                                "name": "refused",
                                "task": {
                                    "type": "assert",
                                    "expr": "false",
                                    "message": "Publication failure after runtime activation",
                                },
                            }
                        ],
                    }
                ]
                path.write_text(yaml.safe_dump(flow, sort_keys=False))
                history, result = self.run_parent(False)
                steps = {
                    step["name"]: step
                    for job in history["jobs"]
                    for step in job["steps"]
                }
                self.assertEqual(steps["forward_runtime-0"]["status"], "succeeded")
                self.assertFalse(result["ready"])
                self.assertEqual(result["target_rollback_completed"], created)
                self.assertEqual(
                    steps["target_rollback"]["status"],
                    "succeeded" if created else "skipped",
                )

    def test_target_failure_skips_source_even_when_contribution_is_returned(self):
        self.prepare_move()
        self.failure.write_text("packages")
        history, result = self.run_parent(False)
        self.assertFalse(result["target_completed"])
        self.assertFalse(result["source_completed"])
        source = next(job for job in history["jobs"] if job["name"] == "source")
        self.assertEqual(source["status"], "skipped")
        steps = {step["name"]: step for job in history["jobs"] for step in job["steps"]}
        for name in ["forward_observer", "forward_cli"]:
            self.assertEqual(steps[name]["status"], "skipped", steps[name])

    def test_residue_is_proven_or_left_retryable_without_failing_the_role(self):
        for fail in [False, True]:
            with self.subTest(fail=fail):
                target = self.input["intent"]["target"]
                target["residue"] = {
                    "ssh": target["ssh"],
                    "residue": {
                        "rows": [
                            {
                                "id": 1,
                                "route_id": 7,
                                "domain": "removed.orbit",
                                "steps": ["certificates", "firewall"],
                                "attempts": 0,
                            }
                        ],
                        "caddy": {},
                        "user": "orbit",
                        "php": None,
                        "certificates": ["route-7"],
                        "firewall": [{"operation": "remove", "route_id": 7}],
                        "ingress": None,
                        "dns": None,
                    },
                }
                if fail:
                    self.failure.write_text("residue")
                else:
                    self.failure.unlink(missing_ok=True)
                history, result = self.run_parent()
                self.assertTrue(result["ready"], result)
                steps = {
                    step["name"]: step
                    for job in history["jobs"]
                    for step in job["steps"]
                }
                self.assertEqual(steps["forward_observer"]["status"], "succeeded")
                self.assertEqual(
                    result["target"]["footprint"]["artifacts"][-1],
                    {
                        "name": "route-residue",
                        "status": "skipped",
                        "reason": "route_residue_cleanup_failed",
                    }
                    if fail
                    else {"name": "route-residue", "status": "applied"},
                )
                self.addCleanup(
                    shutil.rmtree,
                    Path("/var/tmp") / ("orbit-route-residue-" + history["id"]),
                    True,
                )


def load_tests(loader, _tests, _pattern):
    import unittest

    names = [name for name in RoleParentNative.__dict__ if name.startswith("test_")]
    return unittest.TestSuite(RoleParentNative(name) for name in names)


if __name__ == "__main__":
    import unittest

    unittest.main()
