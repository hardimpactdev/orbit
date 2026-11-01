"""Native transfer publication DAGs with the unmodified official file extension."""

import hashlib
import json
import os
import pwd
import shutil
import sys
from pathlib import Path

import yaml

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from tests.native_harness import NativeTestCase
from tests.route_ssh_fixture import PIN, install_ssh

ROOT = Path(__file__).resolve().parents[1]
HEADER = "# Managed by Orbit: Node Caddy build\n{ admin off }\n"


def block(name, target):
    return f"# orbit: routes {name}\nhttps://{name}.test {{ reverse_proxy {target} }}\n"


def config(*blocks):
    return HEADER + ("\n" + "\n".join(blocks) if blocks else "")


def pool(id, value):
    return f"[orbit-instance-{id}]\nchdir = /srv/app-{id}\npm.max_children = {value}\n"


def caddy(content):
    return {
        "content": content,
        "version": hashlib.sha256(content.encode()).hexdigest()[:32],
        "listen_addresses": ["10.44.0.9"],
        "unserved": False,
    }


class PublicationDeltaNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.connection = dict(
            install_ssh(self), nodeIdentityFile="/fixture/existing-key", become=True
        )
        self.copy_behaviors("operations/routes/publication-command")
        shutil.copy(
            ROOT / "extensions/behaviors/operations/routes/caddy-input.ts",
            self.repo / "extensions/behaviors/operations/routes",
        )
        self.host = self.repo / "host"
        self.host.mkdir()
        self.live = self.host / "Caddyfile"
        self.php = self.host / "php/8.4/fpm/pool.d/orbit-scopes.conf"
        self.php.parent.mkdir(parents=True)
        self.audit = self.repo / "audit"
        self.commands = self.repo / "commands"
        self.commands.mkdir()
        script = r"""#!/usr/bin/env python3
import json,os,sys
from pathlib import Path
name=Path(sys.argv[0]).name
with open(os.environ['FIXTURE_AUDIT'],'a') as output:output.write(json.dumps([name,*sys.argv[1:]])+'\n')
if name in ('caddy','php-fpm8.4') and os.environ.get('FIXTURE_FAIL')=='validate':sys.exit(1)
"""
        for name in ["caddy", "php-fpm8.4", "php-fpm8.5", "systemctl"]:
            path = self.commands / name
            path.write_text(script)
            path.chmod(0o700)
        self.native_env["FIXTURE_AUDIT"] = str(self.audit)
        for family in ["caddy", "php"]:
            workflow = yaml.safe_load(
                (
                    ROOT / f"workflows/workflow-operation-route-{family}-delta.yaml"
                ).read_text()
            )

            def patch_strings(value):
                if isinstance(value, str):
                    return (
                        value.replace("/etc/caddy/Caddyfile", str(self.live))
                        .replace("/etc/php/", str(self.host / "php") + "/")
                        .replace("/usr/bin/caddy", str(self.commands / "caddy"))
                        .replace("/usr/sbin/php-fpm", str(self.commands / "php-fpm"))
                        .replace("/usr/bin/systemctl", str(self.commands / "systemctl"))
                    )
                if isinstance(value, list):
                    return [patch_strings(item) for item in value]
                if isinstance(value, dict):
                    return {key: patch_strings(item) for key, item in value.items()}
                return value

            workflow = patch_strings(workflow)
            if family == "php":
                apply = next(
                    step
                    for job in workflow["jobs"]
                    for step in job["steps"]
                    if step["name"] == "apply-pool"
                )
                apply["task"]["inputs"]["argv"][-1] = (
                    "${{ '"
                    + str(self.host / "php")
                    + "/' + self.pool.version + '/fpm/pool.d/orbit-scopes.conf' }}"
                )
            for job in workflow["jobs"]:
                self.assertEqual(job.pop("target"), "orbit-node-209")
                self.assertNotIn("labels", job)
                for step in job["steps"]:
                    self.assertEqual(step.pop("target"), "orbit-node-209")
                    self.assertNotIn("labels", step)
            (
                self.repo / f"workflows/workflow-operation-route-{family}-delta.yaml"
            ).write_text(yaml.safe_dump(workflow, sort_keys=False))
        self.user = pwd.getpwuid(os.getuid()).pw_name

    def run_delta(self, family, before, after, direction="forward", success=True):
        inputs = {
            "operation_id": 17,
            "node_id": 209,
            "kind": "instance.transfer",
            "execution_secrets": {},
            "intent": {
                "node_id": 9,
                "user": self.user,
                "control_node_id": 209,
                "host_keys": [PIN],
                "connection": self.connection,
                "before": before,
                "after": after,
                "direction": direction,
            },
        }
        result = self.cli(
            "workflow",
            "run",
            f"operation-route-{family}-delta",
            "--input",
            json.dumps(inputs),
            success=success,
        )
        return json.loads(result.stdout)

    def php_input(self, text):
        return {"account_user": self.user, "configurations": {"8.4": text}}

    def calls(self):
        return (
            [json.loads(x) for x in self.audit.read_text().splitlines()]
            if self.audit.exists()
            else []
        )

    def test_caddy_forward_and_restore_preserve_observed_unrelated_changes(self):
        a, b = block("moving", "a"), block("moving", "b")
        old, new = block("survivor", "old"), block("survivor", "new")
        before, after = caddy(config(a, old)), caddy(config(b, old))
        current = config(a, new, block("added", "c"))
        self.live.write_text(current)
        result = self.run_delta("caddy", before, after)
        self.assertEqual(self.live.read_text(), config(b, new, block("added", "c")))
        self.assertEqual([row[0] for row in self.calls()], ["caddy", "systemctl"])
        history = json.loads(
            self.cli("workflow", "history", "get", result["id"]).stdout
        )
        terminal = next(
            step
            for job in history["jobs"]
            if job["name"] == "completion"
            for step in job["steps"]
            if step["name"] == "result"
        )
        self.assertTrue(json.loads(terminal["outputs"]["stdout"])["ready"])
        self.run_delta("caddy", before, after, "restore")
        self.assertEqual(self.live.read_text(), current)

    def test_caddy_conflict_refuses_without_writing_or_reloading(self):
        self.live.write_text(config(block("moving", "c")))
        self.run_delta(
            "caddy",
            caddy(config(block("moving", "a"))),
            caddy(config(block("moving", "b"))),
            success=False,
        )
        self.assertEqual(self.live.read_text(), config(block("moving", "c")))
        self.assertEqual(self.calls(), [])

    def test_caddy_validation_failure_skips_reload(self):
        self.native_env["FIXTURE_FAIL"] = "validate"
        self.live.write_text(config())
        self.run_delta(
            "caddy", caddy(config()), caddy(config(block("new", "a"))), success=False
        )
        self.assertEqual([row[0] for row in self.calls()], ["caddy"])

    def test_caddy_initial_publication_uses_official_file_without_staging(self):
        self.run_delta("caddy", caddy(config()), caddy(config(block("new", "a"))))
        self.assertEqual(self.live.read_text(), config(block("new", "a")))
        self.assertEqual(
            sorted(x.name for x in self.host.iterdir()), ["Caddyfile", "php"]
        )

    def test_php_forward_and_restore_keep_unrelated_pools(self):
        a, b, old, new = pool(1, 5), pool(1, 7), pool(2, 3), pool(2, 9)
        self.php.write_text("; local tuning\n" + a + new)
        self.run_delta("php", self.php_input(a + old), self.php_input(b + old))
        self.assertEqual(self.php.read_text(), "; local tuning\n" + b + new)
        self.assertEqual([row[0] for row in self.calls()], ["php-fpm8.4", "systemctl"])
        self.run_delta(
            "php", self.php_input(a + old), self.php_input(b + old), "restore"
        )
        self.assertEqual(self.php.read_text(), "; local tuning\n" + a + new)

    def test_php_final_withdrawal_removes_only_the_managed_pool_file(self):
        self.php.write_text(pool(1, 5))
        local = self.php.parent / "local.conf"
        local.write_text("; local")
        self.run_delta("php", self.php_input(pool(1, 5)), self.php_input(""))
        self.assertFalse(self.php.exists())
        self.assertEqual(local.read_text(), "; local")

    def test_php_owned_conflict_has_no_file_or_service_effect(self):
        self.php.write_text(pool(1, 9))
        self.run_delta(
            "php", self.php_input(pool(1, 5)), self.php_input(pool(1, 7)), success=False
        )
        self.assertEqual(self.php.read_text(), pool(1, 9))
        self.assertEqual(self.calls(), [])

    def test_php_validation_failure_skips_reload(self):
        self.php.write_text(pool(1, 5))
        self.native_env["FIXTURE_FAIL"] = "validate"
        self.run_delta(
            "php", self.php_input(pool(1, 5)), self.php_input(pool(1, 7)), success=False
        )
        self.assertEqual([row[0] for row in self.calls()], ["php-fpm8.4"])

    def test_php_versions_validate_concurrently_without_a_shared_behavior_lock(self):
        second = self.host / "php/8.5/fpm/pool.d/orbit-scopes.conf"
        second.parent.mkdir(parents=True)
        barrier = self.repo / "validation-barrier"
        barrier.mkdir()
        for version in ["8.4", "8.5"]:
            command = self.commands / ("php-fpm" + version)
            command.write_text(command.read_text() + '''
import time
barrier=Path(os.environ['FIXTURE_PHP_BARRIER'])
(barrier/name).touch()
deadline=time.monotonic()+8
while len(list(barrier.iterdir()))<2:
    if time.monotonic()>deadline:raise SystemExit('parallel validation serialized')
    time.sleep(0.02)
''')
        self.native_env["FIXTURE_PHP_BARRIER"] = str(barrier)
        before = {"account_user": self.user, "configurations": {"8.4": "", "8.5": ""}}
        after = {"account_user": self.user, "configurations": {"8.4": pool(1, 5), "8.5": pool(2, 7)}}

        self.run_delta("php", before, after)

        self.assertEqual(self.php.read_text(), pool(1, 5))
        self.assertEqual(second.read_text(), pool(2, 7))
        self.assertCountEqual([p.name for p in barrier.iterdir()], ["php-fpm8.4", "php-fpm8.5"])
