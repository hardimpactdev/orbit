"""Fast, unprivileged tests of the shipped mail converger and its Ansible entry."""

import importlib.util
import json
import os
import shutil
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location(
    "mail", ROOT / "swamp/ansible/instance_mail.py"
)
MAIL = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MAIL)


class MailPhase(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="orbit-mail-")
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.guest = self.root / "guest"
        proxy = self.guest / "usr/lib/systemd/systemd-socket-proxyd"
        proxy.parent.mkdir(parents=True)
        proxy.touch(mode=0o755)
        self.endpoint = {"host": "10.253.0.1", "port": 1025, "docker": True}
        self.units = []
        for port in MAIL.PORTS:
            for kind in ["socket", "service"]:
                self.units.append(
                    {
                        "name": f"orbit-mail-{port}.{kind}",
                        "content": MAIL.MARKER
                        + (
                            f"ListenStream=127.0.0.1:{port}\n"
                            if kind == "socket"
                            else "ExecStart=/usr/lib/systemd/systemd-socket-proxyd 10.253.0.1:1025\n"
                        ),
                    }
                )
        self.plan = {
            "project": "orbit-instances",
            "name": "instance-7",
            "acl": "instance-7",
            "network": "orbitinst0",
            "bridge_cidr": "10.253.0.0/24",
            "bridge_admitted_at": 1800000000000000,
            "mail": self.endpoint,
            "mail_units": self.units,
        }
        self.acl = {
            "description": "Instance 7",
            "egress": [],
            "ingress": [{"action": "allow"}],
        }
        self.bridge = {"egress": []}
        spec = importlib.util.spec_from_file_location(
            "bridge", ROOT / "swamp/ansible/instance_bridge.py"
        )
        self.bridge_module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(self.bridge_module)
        (self.root / "bridge").mkdir()
        (self.root / "bridge/config.json").write_text(
            json.dumps(
                {
                    "network": "orbitinst0",
                    "cidr": "10.253.0.0/24",
                    "admitted_at": 1799999999999999,
                    "baseline": {},
                }
            )
        )

        self.sockets = {}
        self.disabled = set()
        self.services = {}
        self.loaded = {}
        self.calls = []
        self.failure = ""
        self.power = "Running"
        self.foreign = False
        workspace = self.guest / "srv/workspace"
        workspace.mkdir(parents=True)
        (workspace / ".env").write_bytes(
            b"# hand edited\r\nMAIL_MAILER=log\r\nDB_HOST=hand"
        )
        self.before = (workspace / ".env").read_bytes()

    def guest_run(self, argv, content=None):
        self.calls.append(argv)
        if argv[:2] == ["systemctl", "show"]:
            return "\n\n".join(
                "Id=orbit-mail-"
                + str(p)
                + ".socket\nActiveState="
                + ("active" if self.sockets.get(p) else "inactive")
                + "\nUnitFileState="
                + (
                    "enabled"
                    if self.sockets.get(p) and p not in self.disabled
                    else "disabled"
                )
                + (
                    "\nListen=127.0.0.1:" + str(p) + " (Stream)"
                    if self.sockets.get(p)
                    else ""
                )
                for p in MAIL.PORTS
            )
        if argv[0] == "ss":
            return (
                "LISTEN 0 128 0.0.0.0:2525 0.0.0.0:*\n" if self.foreign else ""
            ) + "".join(
                f"LISTEN 0 128 127.0.0.1:{p} 0.0.0.0:*\n"
                for p in MAIL.PORTS
                if self.sockets.get(p)
            )
        if argv == ["systemctl", "daemon-reload"]:
            if self.failure == "reload":
                raise RuntimeError("fixture reload failed")
            for p in (self.guest / "etc/systemd/system").glob("*.service"):
                self.loaded[p.name] = p.read_text()
        elif argv[:2] == ["systemctl", "try-restart"]:
            for name in argv[3:]:
                if name in self.services:
                    self.services[name] = self.loaded[name]
        elif argv[:2] == ["systemctl", "enable"]:
            for name in argv[4:]:
                port = int(name.split("-")[-1].split(".")[0])
                self.sockets[port] = True
                self.disabled.discard(port)
        else:
            raise AssertionError(argv)
        return ""

    def node_run(self, argv, content=None, public_guest=False):
        self.calls.append(argv[:6])
        if argv[0:2] == ["incus", "query"]:
            if "/instances/" in argv[-1]:
                return json.dumps({"status": self.power})
            return json.dumps(self.bridge if "-egress?" in argv[-1] else self.acl)
        if argv[0] == "python3":
            self.assertIn("--intent", argv)
            self.assertNotIn("--reconcile", argv)
            self.assertEqual(json.loads(content)["endpoints"], [self.endpoint])
            if self.failure == "path":
                raise RuntimeError("path failed")

            def api(name, value=None, etag=None, deadline=None):
                if value is not None:
                    self.bridge.update(value)
                return self.bridge, "fixture-etag"

            nat = (
                "-A DOCKER -d 10.253.0.1/32 -p tcp --dport "
                + str(self.endpoint["port"])
                + " -j DNAT --to-destination 172.17.0.7:1025\n"
            )
            with (
                patch.object(self.bridge_module, "api", side_effect=api),
                patch.object(
                    self.bridge_module.subprocess, "check_output", return_value=nat
                ),
            ):
                changed = self.bridge_module.apply(
                    self.root / "bridge", json.loads(content)
                )
            return json.dumps({"changed": changed})
        if argv[:4] == ["incus", "network", "acl", "edit"]:
            self.assertEqual(argv[4], "instance-7")
            self.acl = json.loads(content)
            return ""
        self.assertEqual(argv[:2], ["incus", "exec"])
        self.assertIn("python3", argv)
        action = argv[-1]

        # Only the fixture filesystem is touched; keep production's uid/gid contract explicitly asserted.
        def chown(path, uid, gid):
            self.assertEqual((uid, gid), (0, 0))

        real_stat = Path.stat

        def owned(path, *args, **kwargs):
            info = real_stat(path, *args, **kwargs)
            values = list(info)
            values[4] = values[5] = 0
            return os.stat_result(values)

        real_atomic = MAIL.atomic

        def write(path, value, mode):
            if path.name == self.failure:
                raise RuntimeError("fixture write failed")
            real_atomic(path, value, mode)

        with (
            patch.object(MAIL.os, "chown", side_effect=chown),
            patch.object(Path, "stat", owned),
            patch.object(MAIL, "atomic", side_effect=write),
        ):
            return json.dumps(
                MAIL.guest(action, json.loads(content), self.guest, self.guest_run)
            )

    def apply(self):
        result = MAIL.apply(
            self.plan,
            {"mail": "shipped mail source", "bridge": "shipped bridge source"},
            self.node_run,
            self.root / "state",
        )
        self.assertEqual((self.guest / "srv/workspace/.env").read_bytes(), self.before)
        return result

    def test_two_private_listeners_exact_rule_additive_dnat_refresh_and_no_app_changes(
        self,
    ):
        self.assertEqual(self.apply(), {"status": "applied"})
        self.assertEqual(
            self.acl["egress"],
            [
                {
                    "action": "allow",
                    "destination": "10.253.0.1/32",
                    "protocol": "tcp",
                    "destination_port": "1025",
                    "state": "enabled",
                    "description": "Orbit development SMTP",
                }
            ],
        )
        self.assertEqual(self.sockets, {1025: True, 2525: True})
        self.assertEqual(len(list((self.guest / "etc/systemd/system").iterdir())), 4)
        network = next(
            i
            for i, c in enumerate(self.calls)
            if c[:4] == ["incus", "network", "acl", "edit"]
        )
        reload = self.calls.index(["systemctl", "daemon-reload"])
        self.assertLess(network, reload)
        for path in (self.guest / "etc/systemd/system").iterdir():
            self.assertEqual(path.stat().st_mode & 0o777, 0o644)
        self.assertFalse(any("php" in c or "/srv/workspace" in c for c in self.calls))
        record = next((self.root / "bridge/endpoints").glob("*.json"))
        self.assertEqual(
            json.loads(record.read_text())["translated"],
            {"host": "172.17.0.7", "port": 1025},
        )

        def api(name, value=None, etag=None, deadline=None):
            if value is not None:
                self.bridge.update(value)
            return self.bridge, "fixture-etag"

        with (
            patch.object(self.bridge_module, "api", side_effect=api),
            patch.object(
                self.bridge_module.subprocess,
                "check_output",
                return_value="-A DOCKER -d 10.253.0.1/32 -p tcp --dport 1025 -j DNAT --to-destination 172.17.0.9:1025\n",
            ),
        ):
            self.bridge_module.apply(self.root / "bridge", None)
        self.assertEqual(
            json.loads(record.read_text())["translated"],
            {"host": "172.17.0.9", "port": 1025},
        )

    def test_new_admission_metadata_and_incus_empty_fields_are_unchanged(self):
        self.apply()
        self.acl["egress"][0].update({"source": "", "icmp_type": ""})
        self.plan["bridge_admitted_at"] += 1000
        self.calls.clear()
        paths = list((self.guest / "etc/systemd/system").iterdir())
        stamps = {p.name: p.stat().st_mtime_ns for p in paths}
        self.assertEqual(self.apply(), {"status": "unchanged"})
        self.assertEqual(stamps, {p.name: p.stat().st_mtime_ns for p in paths})
        self.assertFalse(
            any("try-restart" in c or "enable" in c or "edit" in c for c in self.calls)
        )
        self.assertEqual(sum(c[:2] == ["incus", "exec"] for c in self.calls), 1)

    def test_failed_unit_write_keeps_network_path_and_a_retry_completes_it(self):
        self.failure = "orbit-mail-1025.socket"
        with self.assertRaises(RuntimeError):
            self.apply()
        self.assertEqual(len(self.acl["egress"]), 1)
        self.assertTrue(self.bridge["egress"])
        pending = self.root / "state/instance-7/activation.json"
        self.assertEqual(pending.stat().st_mode & 0o777, 0o600)
        self.failure = ""
        self.assertEqual(self.apply()["status"], "applied")
        self.assertFalse(pending.exists())
        self.assertEqual(self.apply()["status"], "unchanged")

    def activation_retry(self, failure):
        self.apply()
        self.services = dict(self.loaded)
        self.services["unrelated.service"] = "untouched"
        self.endpoint["port"] = 2025
        for unit in self.units:
            unit["content"] = unit["content"].replace(
                "10.253.0.1:1025", "10.253.0.1:2025"
            )
        self.failure = failure
        with self.assertRaises(RuntimeError):
            self.apply()
        pending = self.root / "state/instance-7/activation.json"
        self.assertEqual(
            json.loads(pending.read_text()),
            ["orbit-mail-1025.service", "orbit-mail-2525.service"],
        )
        self.assertIn(
            "2025",
            (self.guest / "etc/systemd/system/orbit-mail-1025.service").read_text(),
        )
        self.assertIn("1025", self.services["orbit-mail-1025.service"])
        self.failure = ""
        self.assertEqual(self.apply()["status"], "applied")
        self.assertIn("2025", self.services["orbit-mail-1025.service"])
        self.assertIn("2025", self.services["orbit-mail-2525.service"])
        self.assertEqual(self.services["unrelated.service"], "untouched")
        self.assertFalse(pending.exists())
        self.assertEqual(self.apply()["status"], "unchanged")

    def test_failure_after_one_successful_service_write_finishes_pending_activation(
        self,
    ):
        self.activation_retry("orbit-mail-2525.service")

    def test_failure_after_all_writes_before_reload_finishes_pending_activation(self):
        self.activation_retry("reload")

    def test_foreign_listener_or_unit_skips_both_ports_without_mutation(self):
        self.foreign = True
        self.assertEqual(
            self.apply(), {"status": "skipped", "reason": "mail_port_in_use"}
        )
        self.foreign = False
        folder = self.guest / "etc/systemd/system"
        folder.mkdir(parents=True)
        (folder / "orbit-mail-1025.service").write_text("# Project owned\n")
        self.assertEqual(
            self.apply(), {"status": "skipped", "reason": "mail_unit_conflict"}
        )
        self.assertFalse(self.bridge["egress"])
        self.assertEqual(self.acl["egress"], [])
        self.assertEqual(self.sockets, {})

    def test_stopped_missing_binary_or_path_failure_never_changes_guest(self):
        self.power = "Stopped"
        with self.assertRaisesRegex(RuntimeError, "process.target_inactive"):
            self.apply()
        self.assertFalse(any(c[:2] == ["incus", "exec"] for c in self.calls))
        self.power = "Running"
        binary = self.guest / "usr/lib/systemd/systemd-socket-proxyd"
        binary.chmod(0o644)
        with self.assertRaisesRegex(RuntimeError, "instance.host_unavailable"):
            self.apply()
        binary.chmod(0o755)
        self.failure = "path"
        with self.assertRaises(RuntimeError):
            self.apply()
        self.assertFalse((self.guest / "etc").exists())
        self.assertFalse(self.acl["egress"])

    def test_migrate_selected_smtp_passes_real_preflight_and_installs_forwarders(self):
        spec = importlib.util.spec_from_file_location(
            "preflight", ROOT / "swamp/ansible/library/orbit_migrate_env.py"
        )
        preflight = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(preflight)
        source = self.root / "source"
        source.mkdir()
        (source / ".env").write_text(
            'MAIL_MAILER="smtp"\nMAIL_HOST="127.0.0.1"\nMAIL_PORT="1025"\n'
        )
        self.assertEqual(
            preflight.inspect_environment(str(source), [], [], {}, self.endpoint), []
        )
        self.assertTrue(preflight.inspect_environment(str(source), [], [], {}))
        self.assertEqual(self.apply(), {"status": "applied"})
        self.assertEqual(self.sockets, {1025: True, 2525: True})

    def test_active_owned_socket_with_missing_enable_link_is_repaired(self):
        self.assertEqual(self.apply(), {"status": "applied"})
        self.disabled.add(
            1025
        )  # disable without stopping: the listener is still Orbit's.
        self.calls.clear()
        self.assertEqual(self.apply(), {"status": "applied"})
        self.assertEqual(self.disabled, set())
        self.assertEqual(self.sockets, {1025: True, 2525: True})
        self.assertIn(
            ["systemctl", "enable", "--now", "--", "orbit-mail-1025.socket"], self.calls
        )
        self.assertFalse(any("try-restart" in call for call in self.calls))
        self.assertEqual(self.apply(), {"status": "unchanged"})

    def test_failed_guest_entry_preserves_only_exact_public_codes_at_subprocess_boundary(
        self,
    ):
        # Execute the shipped guest main through a real subprocess, with only Incus replaced.
        binary = self.root / "bin"
        binary.mkdir()
        incus = binary / "incus"
        incus.write_text(
            "#!"
            + sys.executable
            + "\nimport os,sys\na=sys.argv[sys.argv.index('--')+1:]\nos.execv(sys.executable,[sys.executable]+a[1:])\n"
        )
        incus.chmod(0o755)
        systemctl = binary / "systemctl"
        systemctl.write_text(
            "#!/bin/sh\nprintf 'Id=orbit-mail-1025.socket\\nActiveState=inactive\\nUnitFileState=enabled\\n'\n"
        )
        systemctl.chmod(0o755)
        source = (
            (ROOT / "swamp/ansible/instance_mail.py")
            .read_text()
            .replace(
                "guest(sys.argv[1], json.load(sys.stdin))",
                "guest(sys.argv[1], json.load(sys.stdin), root=Path("
                + repr(str(self.guest))
                + "))",
            )
        )

        def invoke(code, action="inspect", plan=None, public=True):
            argv = [
                str(incus),
                "exec",
                "--project",
                "fixture",
                "instance-7",
                "--",
                "python3",
                "-c",
                code,
                action,
            ]
            return MAIL.command(
                argv, json.dumps(plan or self.plan), public_guest=public
            )

        proxy = self.guest / "usr/lib/systemd/systemd-socket-proxyd"
        with patch.dict(os.environ, {"PATH": str(binary) + ":" + os.environ["PATH"]}):
            proxy.chmod(0o644)
            with self.assertRaisesRegex(RuntimeError, "^instance.host_unavailable$"):
                invoke(source)
            proxy.chmod(0o755)
            plan = self.plan | {
                "changed_units": [],
                "activation_units": [],
                "sockets": {
                    f"orbit-mail-{p}.socket": {
                        "ActiveState": "active",
                        "UnitFileState": "enabled",
                        "Listen": f"127.0.0.1:{p} (Stream)",
                    }
                    for p in MAIL.PORTS
                },
            }
            with self.assertRaisesRegex(RuntimeError, "^process.target_inactive$"):
                invoke(source, "install", plan)
            for diagnostic in [
                "process.target_inactive: private",
                "private\ninstance.host_unavailable",
                "instance.host_unavailable\n\n",
            ]:
                code = (
                    "import sys;sys.stderr.write(" + repr(diagnostic) + ");sys.exit(1)"
                )
                with self.assertRaisesRegex(
                    RuntimeError, "^instance.mail_update_failed$"
                ):
                    invoke(code)
            with self.assertRaisesRegex(RuntimeError, "^instance.mail_update_failed$"):
                invoke(
                    "import sys;sys.stderr.write('instance.host_unavailable\\n');sys.exit(1)",
                    public=False,
                )

    @unittest.skipUnless(shutil.which("ansible-playbook"), "pinned Ansible required")
    def test_real_default_launch_continues_after_mail_failure(self):
        import yaml

        tasks = yaml.safe_load(
            (ROOT / "swamp/ansible/tasks/instance-container-phase.yml").read_text()
        )
        mail_block = next(
            t
            for t in tasks
            if t["name"] == "Apply mail before preparing the new workspace"
        )
        assets = self.root / "tasks"
        assets.mkdir()
        shutil.copy(ROOT / "swamp/ansible/tasks/instance-mail.yml", assets)
        for name in ["instance_mail.py", "instance_bridge.py"]:
            shutil.copy(ROOT / "swamp/ansible" / name, self.root)
        binary = self.root / "bin"
        binary.mkdir()
        incus = binary / "incus"
        incus.write_text(
            "#!"
            + sys.executable
            + "\nimport sys,json\nif 'query' in sys.argv: print(json.dumps({'status':'Running'}))\nelse: sys.stderr.write('instance.host_unavailable\\n');sys.exit(1)\n"
        )
        incus.chmod(0o755)
        receipt = self.root / "receipt.json"
        play = [
            {
                "hosts": "localhost",
                "connection": "local",
                "gather_facts": False,
                "vars": {
                    "ansible_python_interpreter": sys.executable,
                    "container": self.plan,
                    "phase": "container",
                },
                "tasks": [
                    mail_block,
                    {
                        "ansible.builtin.copy": {
                            "dest": str(receipt),
                            "mode": "0600",
                            "content": "{{ {'mail': mail_result, 'later_workspace_step': true} | to_json }}",
                        }
                    },
                ],
            }
        ]
        plan = self.root / "play.json"
        plan.write_text(json.dumps(play))
        done = subprocess.run(
            ["ansible-playbook", "-i", "localhost,", str(plan)],
            env=os.environ
            | {
                "PATH": str(binary) + ":" + os.environ["PATH"],
                "ANSIBLE_STDOUT_CALLBACK": "default",
                "ANSIBLE_COLLECTIONS_PATH": str(self.root / "empty"),
                "ANSIBLE_LOCAL_TEMP": str(self.root / "local"),
                "ANSIBLE_REMOTE_TEMP": str(self.root / "remote"),
            },
            capture_output=True,
            text=True,
            timeout=15,
            check=False,
        )
        self.assertEqual(done.returncode, 0, done.stdout + done.stderr)
        self.assertEqual(
            json.loads(receipt.read_text()),
            {
                "mail": {"status": "failed", "reason": "instance.host_unavailable"},
                "later_workspace_step": True,
            },
        )
        self.assertIn("orbit-mail=failed:instance.host_unavailable", done.stdout)

    def test_no_endpoint_does_no_work(self):
        self.plan["mail"] = None
        self.assertIsNone(self.apply())
        self.assertEqual(self.calls, [])

    def test_create_launches_share_the_mail_step_and_receipt(self):
        import yaml

        tasks = yaml.safe_load(
            (ROOT / "swamp/ansible/tasks/instance-container-phase.yml").read_text()
        )
        folded = next(
            t
            for t in tasks
            if t["name"] == "Apply mail before preparing the new workspace"
        )
        self.assertEqual(folded["tags"], ["container", "clone"])
        workflow = yaml.safe_load(
            (
                ROOT / "swamp/workflows/workflow-operation-instance-create.yaml"
            ).read_text()
        )
        steps = {s["name"]: s for j in workflow["jobs"] for s in j["steps"]}
        self.assertNotIn("clone-mail", steps)
        self.assertNotIn("mail", steps)
        self.assertNotIn("clone-node-store", steps)
        self.assertIn("clone-container", str(steps["clone-workspace"]["dependsOn"]))
        self.assertIn('steps["clone-container"]', str(steps["finish"]))
        self.assertIn("steps.container", str(steps["finish"]))
        source = (ROOT / "swamp/scripts/instance_clone.py").read_text()
        self.assertIn("attempted = run(", source)
        self.assertLess(
            source.index('change(project + ["start", name])'),
            source.index("attempted = run("),
        )


if __name__ == "__main__":
    unittest.main()
