"""Actual built-in shell actions in scratch; no live Node, Gateway or credentials."""

import hashlib
import json
import os
import shutil
import subprocess
import sys
import unittest
from pathlib import Path

import yaml

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from tests.native_harness import NativeTestCase

ROOT = Path(__file__).resolve().parents[1]


class CaddyCommandNative(NativeTestCase):
    native_timeout = 100

    def setUp(self):
        super().setUp()
        self.host = self.repo / "host"
        self.host.mkdir()
        self.commands = self.repo / "commands"
        self.commands.mkdir()
        self.audit = self.repo / "command-audit.jsonl"
        command = """#!/usr/bin/env python3
import json, os, sys
from pathlib import Path
name=Path(sys.argv[0]).name
with open(os.environ['FIXTURE_AUDIT'],'a') as file: file.write(json.dumps([name,*sys.argv[1:]])+'\\n')
if name=='systemctl' and sys.argv[1]=='is-active' and os.environ.get('FIXTURE_INACTIVE')=='yes': sys.exit(3)
if name=='systemctl' and sys.argv[1]=='is-enabled' and os.environ.get('FIXTURE_INACTIVE')=='yes': sys.exit(1)
if name=='systemctl' and sys.argv[1]=='enable' and os.environ.get('FIXTURE_REFUSE')=='interleaved':
 root=Path(os.environ['FIXTURE_HOST']); other=root/'newer-config'; other.write_text('# Managed by Orbit: Node Caddy build\\n{ admin off }\\n'); (root/'Caddyfile').unlink(); (root/'Caddyfile').symlink_to(other); sys.exit(1)
if name=='systemctl' and sys.argv[1]=='enable' and os.environ.get('FIXTURE_REFUSE')=='identical':
 root=Path(os.environ['FIXTURE_HOST']); live=root/'Caddyfile'; content=live.read_bytes(); other=root/'orbit-versions'/'newer-identical'; other.mkdir(); (other/'Caddyfile').write_bytes(content); (other/'loaded.json').write_text(json.dumps({'sha256':__import__('hashlib').sha256(content).hexdigest(),'invocation_id':'a'*32})); live.unlink(); live.symlink_to(other/'Caddyfile'); sys.exit(1)
if name=='systemctl' and sys.argv[1]=='show': print('a'*32)
if name=='caddy': print('v2.10.2 fixture')
if name=='ip': print('1: wg0 inet 10.44.0.9/24 scope global')
if name=='runuser' and os.environ.get('FIXTURE_REFUSE')=='validate': sys.exit(1)
if name=='systemctl' and sys.argv[1]=='reload-or-restart' and os.environ.get('FIXTURE_REFUSE')=='reload' and not Path(os.environ['FIXTURE_AUDIT']+'.failed').exists():
 Path(os.environ['FIXTURE_AUDIT']+'.failed').touch(); sys.exit(1)
"""
        for name in ["caddy", "ip", "runuser", "systemctl"]:
            path = self.commands / name
            path.write_text(command)
            path.chmod(0o755)
        self.workflow = yaml.safe_load(
            (ROOT / "workflows/workflow-operation-route-caddy.yaml").read_text()
        )
        for job in self.workflow["jobs"]:
            job.pop("target")
            job.pop("labels")
            for step in job["steps"]:
                self.assertIn("target", step)
                self.assertIn("labels", step)
                step.pop("target")
                step.pop("labels")
                task = step["task"]
                if task["behaviorType"] != "command/shell":
                    continue
                script = task["inputs"]["run"]
                self.assertNotIn("GatewayOperation", script)
                import re

                script = re.sub(r"sudo --preserve-env=[A-Z_,]+ ", "", script)
                script = script.replace("sudo /usr/bin/python3", "/usr/bin/python3")
                script = script.replace(
                    'Path("/etc/caddy")', repr(self.host).replace("PosixPath", "Path")
                )
                script = script.replace(
                    "Path('/etc/caddy')", repr(self.host).replace("PosixPath", "Path")
                )
                script = script.replace(".st_uid != 0", ".st_uid != os.getuid()")
                script = script.replace('grp.getgrnam("caddy").gr_gid', "os.getgid()")
                script = script.replace("grp.getgrnam('caddy').gr_gid", "os.getgid()")
                script = script.replace(
                    "os.chown(directory, 0, gid)",
                    "os.chown(directory, os.getuid(), gid)",
                )
                script = script.replace(
                    "os.fchown(output.fileno(), 0, gid)",
                    "os.fchown(output.fileno(), os.getuid(), gid)",
                )
                script = re.sub(
                    r"os.chown\(([^,\n]+), 0, gid\)",
                    r"os.chown(\1, os.getuid(), gid)",
                    script,
                )
                for binary, name in [
                    ("/usr/bin/caddy", "caddy"),
                    ("/usr/sbin/ip", "ip"),
                    ("/usr/sbin/runuser", "runuser"),
                    ("/usr/bin/systemctl", "systemctl"),
                ]:
                    script = script.replace(binary, str(self.commands / name))
                task["inputs"]["run"] = script
                task["inputs"]["env"].update(
                    {
                        "FIXTURE_AUDIT": str(self.audit),
                        "FIXTURE_HOST": str(self.host),
                        "FIXTURE_REFUSE": "${{ inputs.fixture_refuse }}",
                        "FIXTURE_INACTIVE": "${{ inputs.fixture_inactive }}",
                    }
                )
        self.workflow["inputs"]["properties"]["fixture_refuse"] = {
            "type": "string",
            "default": "",
        }
        self.workflow["inputs"]["properties"]["fixture_inactive"] = {
            "type": "string",
            "default": "",
        }
        (self.repo / "workflows/workflow-operation-route-caddy.yaml").write_text(
            yaml.safe_dump(self.workflow, sort_keys=False)
        )
        folder = self.repo / "extensions/behaviors/operations/routes"
        folder.mkdir(parents=True)
        shutil.copy(
            ROOT / "extensions/behaviors/operations/routes/caddy-input.ts", folder
        )
        self.content = "# Managed by Orbit: Node Caddy build\n{ }\n"
        self.version = hashlib.sha256(self.content.encode()).hexdigest()[:32]
        self.input = {
            "operation_id": 17,
            "kind": "route.caddy_converge",
            "execution_secrets": {},
            "node_id": 9,
            "intent": {
                "caddy_publication": {
                    "node_id": 9,
                    "user": "orbit",
                    "caddy": {
                        "content": self.content,
                        "version": self.version,
                        "listen_addresses": ["10.44.0.9"],
                        "unserved": False,
                    },
                }
            },
        }

    def test_failure_restoration_preserves_a_newer_publication(self):
        previous = self.host / "Caddyfile"
        previous.write_text("# Managed by Orbit: Node Caddy build\n{ debug }\n")
        result = self.cli(
            "workflow",
            "run",
            "operation-route-caddy",
            "--input",
            json.dumps(dict(self.input, fixture_refuse="interleaved")),
            success=False,
        )
        self.assertIn("failed", result.stdout)
        self.assertEqual(
            previous.read_text(),
            "# Managed by Orbit: Node Caddy build\n{ admin off }\n",
        )
        self.assertEqual(previous.resolve(), self.host / "newer-config")

    def test_compensation_preserves_newer_identical_loaded_generation(self):
        self.run_caddy("identical", success=False)
        live = self.host / "Caddyfile"
        self.assertEqual(live.read_text(), self.content)
        self.assertEqual(live.resolve().parent.name, "newer-identical")
        self.assertTrue((live.resolve().parent / "loaded.json").is_file())

    def test_changed_service_invocation_requires_new_load_receipt(self):
        self.run_caddy()
        systemctl = self.commands / "systemctl"
        systemctl.write_text(
            systemctl.read_text().replace("print('a'*32)", "print('b'*32)")
        )
        self.audit.unlink()
        self.run_caddy()
        calls = [json.loads(line) for line in self.audit.read_text().splitlines()]
        self.assertIn(["systemctl", "reload-or-restart", "caddy"], calls)
        receipt = (self.host / "Caddyfile").resolve().parent / "loaded.json"
        self.assertEqual(json.loads(receipt.read_text())["invocation_id"], "b" * 32)

    def test_restore_invalidates_a_prior_load_before_reloading_it(self):
        run_id = "fb062341-cd94-4b4b-b692-da0132158756"
        versions = self.host / "orbit-versions"
        old = versions / "prior"
        new = versions / (self.version + "-" + run_id)
        old.mkdir(parents=True)
        new.mkdir()
        prior_content = "# Managed by Orbit: Node Caddy build\n{ debug }\n"
        (old / "Caddyfile").write_text(prior_content)
        (new / "Caddyfile").write_text(self.content)
        old_digest = hashlib.sha256(prior_content.encode()).hexdigest()
        for generation, sha in [
            (old, old_digest),
            (new, hashlib.sha256(self.content.encode()).hexdigest()),
        ]:
            (generation / "loaded.json").write_text(
                json.dumps({"sha256": sha, "invocation_id": "a" * 32})
            )
        (self.host / "Caddyfile").symlink_to(new / "Caddyfile")
        stage = self.host / ".orbit-staging" / run_id
        stage.mkdir(parents=True)
        (stage / "prior").symlink_to(old / "Caddyfile")
        (stage / "restore.json").write_text(
            json.dumps(
                {
                    "had_live": True,
                    "previous_target": str(old / "Caddyfile"),
                    "prior_target": str(old / "Caddyfile"),
                    "prior_digest": old_digest,
                    "active": True,
                    "enabled": True,
                }
            )
        )
        step = next(
            step
            for job in self.workflow["jobs"]
            for step in job["steps"]
            if step["name"] == "restore-config"
        )
        script = step["task"]["inputs"]["run"]
        meta = {
            "run_id": run_id,
            "version": self.version,
            "sha256": hashlib.sha256(self.content.encode()).hexdigest(),
        }
        reply = subprocess.run(
            ["/bin/sh", "-c", script],
            env=dict(os.environ, ORBIT_CADDY_META=json.dumps(meta)),
            text=True,
            capture_output=True,
        )
        self.assertEqual(reply.returncode, 0, reply.stderr)
        self.assertEqual((self.host / "Caddyfile").read_text(), prior_content)
        self.assertFalse((old / "loaded.json").exists())

    def test_terminal_observation_reads_actual_root_owned_caddy_permissions(self):
        if (
            shutil.which("sudo") is None
            or subprocess.run(
                ["sudo", "-n", "/usr/bin/true"], check=False, capture_output=True
            ).returncode
        ):
            self.skipTest("Root-owned Caddy permissions require passwordless sudo")
        generation = self.host / "orbit-versions" / "protected-generation"
        candidate = self.repo / "public-config"
        candidate.write_text(self.content)
        subprocess.run(
            [
                "sudo",
                "-n",
                "/usr/bin/install",
                "-d",
                "-m",
                "0750",
                "-o",
                "root",
                "-g",
                "root",
                str(generation),
            ],
            check=True,
        )
        try:
            subprocess.run(
                [
                    "sudo",
                    "-n",
                    "/usr/bin/install",
                    "-m",
                    "0640",
                    "-o",
                    "root",
                    "-g",
                    "root",
                    str(candidate),
                    str(generation / "Caddyfile"),
                ],
                check=True,
            )
            candidate.write_text(
                json.dumps(
                    {
                        "sha256": hashlib.sha256(self.content.encode()).hexdigest(),
                        "invocation_id": "a" * 32,
                    }
                )
            )
            subprocess.run(
                [
                    "sudo",
                    "-n",
                    "/usr/bin/install",
                    "-m",
                    "0640",
                    "-o",
                    "root",
                    "-g",
                    "root",
                    str(candidate),
                    str(generation / "loaded.json"),
                ],
                check=True,
            )
            (self.host / "Caddyfile").symlink_to(generation / "Caddyfile")
            with self.assertRaises(PermissionError):
                (self.host / "Caddyfile").read_bytes()
            workflow = yaml.safe_load(
                (ROOT / "workflows/workflow-operation-route-caddy.yaml").read_text()
            )
            script = workflow["jobs"][-1]["steps"][0]["task"]["inputs"]["run"]
            script = script.replace("/etc/caddy", str(self.host))
            script = script.replace(
                "/usr/bin/systemctl", str(self.commands / "systemctl")
            )
            script = script.replace(
                "--preserve-env=ORBIT_CADDY_META,ORBIT_CADDY_OBSERVED",
                "--preserve-env=ORBIT_CADDY_META,ORBIT_CADDY_OBSERVED,FIXTURE_AUDIT",
            )
            env = dict(
                os.environ,
                ORBIT_CADDY_META=json.dumps(
                    {
                        "run_id": "test",
                        "sha256": hashlib.sha256(self.content.encode()).hexdigest(),
                        "version": self.version,
                    }
                ),
                ORBIT_CADDY_OBSERVED=json.dumps({"withdraw": False, "publish": True}),
                FIXTURE_AUDIT=str(self.audit),
            )
            reply = subprocess.run(
                ["/bin/sh", "-c", script],
                env=env,
                capture_output=True,
                text=True,
                check=False,
            )
            self.assertEqual(reply.returncode, 0, reply.stderr)
            self.assertEqual(
                json.loads(reply.stdout),
                {"ready": True, "result": "published", "version": self.version},
            )
        finally:
            subprocess.run(
                ["sudo", "-n", "/usr/bin/rm", "-rf", str(generation)], check=True
            )

    def run_caddy(self, refusal="", success=True, inactive=False):
        self.input["fixture_refuse"] = refusal
        self.input["fixture_inactive"] = "yes" if inactive else ""
        return self.cli(
            "workflow",
            "run",
            "operation-route-caddy",
            "--input",
            json.dumps(self.input),
            success=success,
        )

    def test_native_publish_writes_candidate_then_activates_and_reloads(self):
        self.run_caddy()
        live = self.host / "Caddyfile"
        self.assertEqual(live.read_text(), self.content)
        calls = [json.loads(line) for line in self.audit.read_text().splitlines()]
        self.assertLess(
            next(i for i, row in enumerate(calls) if row[0] == "runuser"),
            next(
                i
                for i, row in enumerate(calls)
                if row[:2] == ["systemctl", "reload-or-restart"]
            ),
        )

    def test_native_validation_failure_preserves_original(self):
        live = self.host / "Caddyfile"
        live.write_text("keep original")
        self.run_caddy("validate", success=False)
        self.assertEqual(live.read_text(), "keep original")
        calls = [json.loads(line) for line in self.audit.read_text().splitlines()]
        self.assertFalse(
            any(
                row[0] == "systemctl"
                and row[1] not in ("is-active", "is-enabled", "show")
                for row in calls
            )
        )

    def test_native_reload_failure_restores_original_through_failure_dependency(self):
        live = self.host / "Caddyfile"
        live.write_text("keep original")
        self.run_caddy("reload", success=False)
        self.assertEqual(live.read_text(), "keep original")
        calls = [json.loads(line) for line in self.audit.read_text().splitlines()]
        self.assertEqual(
            sum(row[:2] == ["systemctl", "reload-or-restart"] for row in calls), 2
        )

    def test_identical_but_not_loaded_config_requires_actual_reload(self):
        (self.host / "Caddyfile").write_text(self.content)
        self.run_caddy()
        calls = [json.loads(line) for line in self.audit.read_text().splitlines()]
        self.assertIn(["systemctl", "reload-or-restart", "caddy"], calls)
        self.assertTrue(
            ((self.host / "Caddyfile").resolve().parent / "loaded.json").is_file()
        )

    def test_composed_candidate_refuses_an_intervening_revision_before_effects(self):
        live = self.host / "Caddyfile"
        live.write_text("newer accepted config")
        self.input["intent"]["caddy_publication"]["expected_current"] = {"sha256": "a" * 64, "target": str(live)}
        self.run_caddy(success=False)
        self.assertEqual(live.read_text(), "newer accepted config")
        self.assertFalse((self.host / ".orbit-staging").exists())
        self.assertFalse(self.audit.exists())

    def test_native_unchanged_does_not_stage_enable_or_reload(self):
        self.run_caddy()
        prior_stages = list((self.host / ".orbit-staging").iterdir())
        self.audit.unlink()
        self.run_caddy()
        calls = [json.loads(line) for line in self.audit.read_text().splitlines()]
        self.assertFalse(
            any(
                row[0] == "systemctl"
                and row[1] not in ("is-active", "is-enabled", "show")
                for row in calls
            )
        )
        self.assertEqual(list((self.host / ".orbit-staging").iterdir()), prior_stages)

    def test_native_unserved_without_caddy_does_not_require_package_or_group(self):
        (self.commands / "caddy").unlink()
        self.input["intent"]["caddy_publication"]["caddy"]["unserved"] = True
        live = self.host / "Caddyfile"
        live.write_text("foreign but retained")
        self.run_caddy(inactive=True)
        self.assertFalse(live.exists())
        self.assertEqual(
            [
                path.read_text()
                for path in (self.host / "orbit-backups").glob("*/Caddyfile")
            ],
            ["foreign but retained"],
        )
        self.assertFalse((self.host / ".orbit-staging").exists())
        calls = [json.loads(line) for line in self.audit.read_text().splitlines()]
        self.assertFalse(
            any(
                row[0] == "systemctl"
                and row[1] not in ("is-active", "is-enabled", "show")
                for row in calls
            )
        )

    def test_native_resume_requires_reactivation_after_compensation(self):
        import re

        live = self.host / "Caddyfile"
        live.write_text("keep original")
        failed = self.run_caddy("reload", success=False)
        run_id = re.search(
            r'"id":\s*"([a-f0-9-]+)",\s*"workflowId":', failed.stdout
        ).group(1)
        self.assertEqual(live.read_text(), "keep original")
        self.cli(
            "workflow",
            "resume",
            "operation-route-caddy",
            "--run",
            run_id,
            success=False,
        )
        self.assertEqual(live.read_text(), "keep original")
        self.cli(
            "workflow",
            "resume",
            "operation-route-caddy",
            "--run",
            run_id,
            "--from",
            "activate-config",
        )
        self.assertEqual(live.read_text(), self.content)

    def test_native_multiple_chunks_preserve_non_ascii_content(self):
        self.content = (
            "# Managed by Orbit: Node Caddy build\n" + "# café settings\n" * 4000
        )
        self.version = hashlib.sha256(self.content.encode()).hexdigest()[:32]
        self.input["intent"]["caddy_publication"]["caddy"].update(
            {"content": self.content, "version": self.version}
        )
        self.run_caddy()
        self.assertEqual((self.host / "Caddyfile").read_text(), self.content)

    def test_failure_restores_previously_disabled_inactive_service_state(self):
        (self.host / "Caddyfile").write_text("keep original")
        self.run_caddy("reload", success=False, inactive=True)
        calls = [json.loads(line) for line in self.audit.read_text().splitlines()]
        self.assertIn(["systemctl", "stop", "caddy"], calls)
        self.assertIn(["systemctl", "disable", "--quiet", "caddy"], calls)
        self.assertEqual((self.host / "Caddyfile").read_text(), "keep original")

    def test_filesystem_cleanup_bounds_completed_stages_and_preserves_active_generation(
        self,
    ):
        foreign = "# custom Caddy config\nlocalhost { respond custom }\n"
        (self.host / "Caddyfile").write_text(foreign)
        self.run_caddy()
        current = next((self.host / ".orbit-staging").iterdir())
        os.utime(current, (0, 0))
        for index in range(16):
            stage = current.parent / f"00000000-0000-4000-8000-{index:012d}"
            stage.mkdir()
            (stage / "complete.json").write_text("{}")
            os.utime(stage, (index, index))
            content = f"# Managed by Orbit: Node Caddy build\n# generation {index}\n"
            version = hashlib.sha256(content.encode()).hexdigest()[:32]
            generation = self.host / "orbit-versions" / version
            generation.mkdir()
            (generation / "Caddyfile").write_text(content)
            os.utime(generation, (index, index))
        script = next(
            step["task"]["inputs"]["run"]
            for job in self.workflow["jobs"]
            for step in job["steps"]
            if step["name"] == "clean-published-candidates"
        )
        reply = subprocess.run(
            ["sh", "-c", script],
            env={
                **os.environ,
                "ORBIT_CADDY_META": json.dumps(
                    {"run_id": "00000000-0000-4000-8000-999999999999"}
                ),
            },
            capture_output=True,
            text=True,
            timeout=20,
            check=False,
        )
        self.assertEqual(reply.returncode, 0, reply.stderr)
        self.assertLessEqual(len(list(current.parent.iterdir())), 11)
        self.assertEqual(len(list((self.host / "orbit-versions").iterdir())), 10)
        self.assertEqual((self.host / "Caddyfile").read_text(), self.content)
        self.assertFalse(current.exists())
        backups = list((self.host / "orbit-backups").glob("*/Caddyfile"))
        self.assertEqual([path.read_text() for path in backups], [foreign])

    def test_no_gateway_endpoint_or_secret_input_in_production_workflow(self):
        source = (ROOT / "workflows/workflow-operation-route-caddy.yaml").read_text()
        self.assertNotIn("gateway", source.lower())
        self.assertNotIn("vault.get", source)
        self.assertNotIn("sleep(", source)
        self.assertNotIn("flock", source)
        self.assertNotIn("queueTimeout", source)


if __name__ == "__main__":
    unittest.main()
