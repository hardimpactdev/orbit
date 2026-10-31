"""Native shared PHP delta actions against owned scratch hosts and tool fixtures."""

import json
import os
import pwd
import re
import shutil
import sys
from pathlib import Path

import yaml

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from tests.cfgmgmt_fixture import install_cfgmgmt
from tests.native_harness import NativeTestCase

ROOT = Path(__file__).resolve().parents[1]
FAMILY = "operations/routes/publication-command"


def pool(number, directory, extra=""):
    return (
        f"[orbit-instance-{number}]\nchdir = {directory}\nlisten = /run/php/instance-{number}.sock\n"
        + extra
    )


class PhpDeltaNative(NativeTestCase):
    native_timeout = 100

    def setUp(self):
        super().setUp()
        connection = install_cfgmgmt(self)
        self.copy_behaviors(FAMILY)
        shutil.copy(
            ROOT / "extensions/behaviors/operations/routes/caddy-input.ts",
            self.repo / "extensions/behaviors/operations/routes/caddy-input.ts",
        )
        self.host = self.repo / "host"
        self.root = self.host / "8.5/fpm"
        (self.root / "pool.d").mkdir(parents=True)
        self.live = self.root / "pool.d/orbit-scopes.conf"
        self.service = self.host / "service.json"
        self.service.write_text(
            json.dumps(
                {"active": True, "enabled": True, "calls": [], "fail_reload": False}
            )
        )
        self.app = self.host / "application"
        self.app.mkdir()
        self.a = pool(1, self.app, "; original\n")
        self.b = pool(1, self.app, "; destination\n")
        self.survivor = pool(2, self.app, "pm.max_children = 31\n")
        self.revised = pool(2, self.app, "pm.max_children = 47\n")
        self.live.write_text(self.a + self.revised)
        (self.root / "php-fpm.conf").write_text(
            f"include = {self.root}/pool.d/*.conf\n"
        )
        self.user = pwd.getpwuid(os.getuid()).pw_name
        command = self.host / "systemctl"
        command.write_text(
            """#!/usr/bin/env python3
import json, sys
from pathlib import Path
p=Path(STATE);s=json.loads(p.read_text());action=sys.argv[1];s['calls'].append(action)
code=0
if action=='is-active': code=0 if s['active'] else 3
elif action=='is-enabled': code=0 if s['enabled'] else 1
elif action=='show': print('a'*32)
elif action=='enable': s['enabled']=True
elif action=='disable': s['enabled']=False
elif action=='stop': s['active']=False
elif action=='reload-or-restart':
 if s['fail_reload']: s['fail_reload']=False;code=1
 else: s['active']=True
p.write_text(json.dumps(s));sys.exit(code)
""".replace("STATE", repr(str(self.service)))
        )
        command.chmod(0o700)
        fpm = self.host / "php-fpm"
        fpm.write_text("#!/usr/bin/env python3\n")
        fpm.chmod(0o700)
        scripts = self.repo / "extensions/behaviors" / FAMILY / "scripts"
        for path in scripts.glob("*.py"):
            text = path.read_text().replace(
                'Path("/etc/php")', f"Path({str(self.host)!r})"
            )
            text = text.replace(".st_uid != 0", ".st_uid != os.getuid()")
            text = text.replace('"/usr/bin/systemctl"', repr(str(command)))
            text = text.replace('f"/usr/sbin/php-fpm{version}"', repr(str(fpm)))
            path.write_text(text)
        assets = self.repo / "extensions/behaviors" / FAMILY / "assets.ts"
        assets.write_text(
            assets.read_text().replace('"/etc/php"', repr(str(self.host)))
        )
        workflow = yaml.safe_load(
            (ROOT / "workflows/workflow-operation-route-php-delta.yaml").read_text()
        )
        for job in workflow["jobs"]:
            job.pop("target")
            job.pop("labels")
            for step in job["steps"]:
                self.assertIn("target", step)
                self.assertIn("labels", step)
                step.pop("target")
                step.pop("labels")
                task = step["task"]
                if task.get("behaviorType", "").startswith("@swamp/cfgmgmt/"):
                    task["globalArgs"]["become"] = False
                if task.get("behaviorType") == "command/shell":
                    task["inputs"]["run"] = re.sub(
                        r"sudo --preserve-env=[A-Z_,]+ ", "", task["inputs"]["run"]
                    )
        (self.repo / "workflows/workflow-operation-route-php-delta.yaml").write_text(
            yaml.safe_dump(workflow, sort_keys=False)
        )
        self.inputs = {
            "operation_id": 17,
            "node_id": 209,
            "kind": "instance.transfer",
            "execution_secrets": {},
            "intent": {
                "node_id": 9,
                "connection": connection,
                "user": self.user,
                "before": {
                    "account_user": self.user,
                    "configurations": {"8.5": self.a + self.survivor},
                },
                "after": {
                    "account_user": self.user,
                    "configurations": {"8.5": self.b + self.survivor},
                },
                "direction": "forward",
            },
        }

    def run_delta(self, success=True):
        return self.cli(
            "workflow",
            "run",
            "operation-route-php-delta",
            "--input",
            json.dumps(self.inputs),
            success=success,
        )

    def test_forward_inverse_preserves_current_unrelated_pool_and_removes_scratch(self):
        self.run_delta()
        self.assertEqual(self.live.read_text(), self.b + self.revised)
        self.assertFalse(any((self.host / ".orbit-deltas").iterdir()))
        self.inputs["intent"]["direction"] = "restore"
        self.run_delta()
        self.assertEqual(self.live.read_text(), self.a + self.revised)
        for path in (self.repo / "data").rglob("*"):
            if path.is_file():
                self.assertNotIn(b"pm.max_children = 47", path.read_bytes())

    def test_conflicting_owned_revision_refuses_without_config_or_service_mutation(
        self,
    ):
        conflict = (
            pool(1, self.app, "; changed by another publication\n") + self.revised
        )
        self.live.write_text(conflict)
        self.run_delta(False)
        self.assertEqual(self.live.read_text(), conflict)
        self.assertFalse(
            set(json.loads(self.service.read_text())["calls"])
            & {"enable", "reload-or-restart", "stop", "disable"}
        )

    def test_failed_reload_restores_prior_file_and_stopped_service(self):
        state = json.loads(self.service.read_text())
        state.update(active=False, enabled=False, fail_reload=True)
        self.service.write_text(json.dumps(state))
        self.run_delta(False)
        self.assertEqual(self.live.read_text(), self.a + self.revised)
        state = json.loads(self.service.read_text())
        self.assertFalse(state["active"])
        self.assertFalse(state["enabled"])
        self.assertIn("stop", state["calls"])

    def test_resume_recomposes_after_an_unrelated_revision(self):
        workflow_path = self.repo / "workflows/workflow-operation-route-php-delta.yaml"
        workflow = yaml.safe_load(workflow_path.read_text())
        publication = next(
            job for job in workflow["jobs"] if job["name"] == "publication"
        )
        validate = next(
            step for step in publication["steps"] if step["name"] == "validate"
        )
        marker = self.host / "intervened"
        replacement = self.host / "intervening-file"
        added = pool(3, self.app, "; added concurrently\n")
        replacement.write_text(self.a + self.revised + added)
        validate["task"]["inputs"]["run"] += (
            f"if [ ! -e {str(marker)!r} ]; then\n"
            f"  /usr/bin/mv {str(replacement)!r} {str(self.live)!r}\n"
            f"  /usr/bin/touch {str(marker)!r}\nfi\n"
        )
        workflow_path.write_text(yaml.safe_dump(workflow, sort_keys=False))
        report = json.loads(self.run_delta(False).stdout)
        self.assertEqual(self.live.read_text(), self.a + self.revised + added)
        self.cli(
            "workflow",
            "resume",
            "operation-route-php-delta",
            "--run",
            report["id"],
            "--from",
            "stage",
        )
        self.assertEqual(self.live.read_text(), self.b + self.revised + added)

    def test_last_pool_withdrawal_removes_managed_file(self):
        self.live.write_text(self.a)
        self.inputs["intent"]["before"]["configurations"] = {"8.5": self.a}
        self.inputs["intent"]["after"]["configurations"] = {}
        self.run_delta()
        self.assertFalse(self.live.exists())


if __name__ == "__main__":
    import unittest

    unittest.main()
