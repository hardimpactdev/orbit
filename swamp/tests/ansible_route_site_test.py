"""Fast unprivileged Route file, custody and production-front-end regressions."""

import importlib.util
import json
import os
import pwd
import re
import shutil
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

import yaml

ROOT = Path(__file__).resolve().parents[2]
SOURCE = ROOT / "swamp/ansible/library/orbit_route_site.py"
SPEC = importlib.util.spec_from_file_location("route_site", SOURCE)
SITE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(SITE)


class RouteSiteTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="orbit-route-")
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.calls = []
        self.owners = []
        self.file_owners = {}
        self.physical_group = None
        self.failure = ""
        self.user = SimpleNamespace(
            pw_uid=os.getuid(), pw_gid=os.getgid(), pw_dir="/home/orbit"
        )
        self.plan = {
            "nodes": {"9": {"account": "orbit"}},
            "leaf_indexes": [0, 1],
            "leaves": [
                {
                    "node_id": 9,
                    "directory": "/certs/v1",
                    "current": "/certs/current",
                    "target": "v1",
                    "scope": "speed",
                    "user": "orbit",
                },
                {
                    "node_id": 10,
                    "directory": "/other/v1",
                    "current": "/other/current",
                    "target": "v1",
                    "scope": "other",
                    "user": "other",
                },
            ],
            "trust": [9],
            "root_certificate": "public root\n",
            "caddy": [
                {"node_id": 9, "content": "# literal {{ preserved }}\n"},
                {"node_id": 10, "content": "other Node"},
            ],
            "awake": [{"node_id": 9, "path": "/awake"}],
        }
        for part in ["certs/v1", "usr/local/share/ca-certificates", "etc/caddy"]:
            (self.root / part).mkdir(parents=True)
        (self.root / "certs/v1/key.pem").write_text("PRIVATE FIXTURE KEY\n")
        self.signed = [
            "prefix-----BEGIN CERTIFICATE-----\npublic leaf\n-----END CERTIFICATE-----\n",
            "unused",
        ]

    def tool_run(self, argv):
        self.calls.append(argv)
        if "validate" in argv:
            validated = Path(argv[argv.index("--config") + 1]).stat()
            self.validation_metadata = (
                validated.st_ino,
                validated.st_uid,
                validated.st_gid,
                validated.st_mode & 0o7777,
            )
        if "validate" in argv and self.failure == "validate":
            raise RuntimeError("validation failure")
        if "reload" in argv and self.failure == "reload":
            raise RuntimeError("reload failure")
        if "install" in argv:
            self.assertEqual(argv[argv.index("-m") + 1], "0600")
            self.assertEqual(argv[argv.index("-o") + 1], "orbit")
            dest = Path(argv[argv.index("-t") + 1])
            dest.mkdir(parents=True, exist_ok=True)
            for value in argv[-2:]:
                target = dest / Path(value).name
                shutil.copyfile(value, target)
                target.chmod(0o600)

    def apply(self):
        # Assert production ownership while modeling it for comparisons on an unprivileged fixture.
        real_stat = SITE.os.fstat
        real_chown = SITE.os.fchown

        def chown(fd, uid, gid):
            current = real_stat(fd)
            self.owners.append((uid, gid))
            self.file_owners[(current.st_dev, current.st_ino)] = (uid, gid)
            if self.physical_group is not None:
                real_chown(
                    fd, os.getuid(), self.physical_group if gid == 123 else os.getgid()
                )

        def node_stat(fd):
            current = real_stat(fd)
            owner = self.file_owners.get((current.st_dev, current.st_ino))
            if owner is None:
                return current
            fields = list(current)
            fields[4], fields[5] = owner
            return os.stat_result(fields)

        with (
            patch.object(SITE.os, "fstat", side_effect=node_stat),
            patch.object(
                SITE.os,
                "fchown",
                side_effect=chown,
            ),
            patch.object(
                SITE.os,
                "chown",
                side_effect=AssertionError(
                    "metadata repair must use a no-follow descriptor"
                ),
            ),
            patch.object(
                SITE.os,
                "lchown",
                side_effect=lambda p, uid, gid: self.owners.append((uid, gid)),
            ),
        ):
            return SITE.converge(
                self.plan,
                9,
                self.signed,
                self.tool_run,
                self.root,
                lambda name: (
                    self.user
                    if name == "orbit"
                    else self.fail("foreign account lookup")
                ),
                lambda name: (
                    SimpleNamespace(gr_gid=123)
                    if name == "caddy"
                    else self.fail("wrong group")
                ),
            )

    def test_certificate_links_account_copy_modes_and_literal_caddy_text(self):
        result = self.apply()
        self.assertTrue(result["changed"])
        self.assertNotIn("PRIVATE FIXTURE KEY", json.dumps(result))
        self.assertEqual((self.root / "certs/current").readlink(), Path("v1"))
        self.assertEqual(
            (self.root / "home/orbit/.orbit/certificates/speed/current").readlink(),
            Path("v1"),
        )
        self.assertEqual(
            (self.root / "etc/caddy/Caddyfile").read_text(),
            "# literal {{ preserved }}\n",
        )
        self.assertEqual(
            (self.root / "etc/caddy/Caddyfile").stat().st_mode & 0o777, 0o640
        )
        self.assertEqual(
            (self.root / "certs/v1/cert.pem").stat().st_mode & 0o777, 0o644
        )
        self.assertEqual(
            (self.root / "home/orbit/.orbit/certificates/speed/v1/key.pem")
            .stat()
            .st_mode
            & 0o777,
            0o600,
        )
        self.assertIn((0, 123), self.owners)
        self.assertIn((0, 0), self.owners)
        self.assertFalse((self.root / "other").exists())
        self.assertTrue((self.root / "awake").exists())
        validate = next(i for i, c in enumerate(self.calls) if "validate" in c)
        reload = next(i for i, c in enumerate(self.calls) if "reload" in c)
        trust = next(
            i for i, c in enumerate(self.calls) if "update-ca-certificates" in c
        )
        self.assertLess(trust, validate)
        self.assertLess(validate, reload)

    def test_existing_file_mode_drift_clears_unwanted_special_bits(self):
        self.apply()
        caddy = self.root / "etc/caddy/Caddyfile"
        caddy.chmod(0o4640)
        self.apply()
        self.assertEqual(caddy.stat().st_mode & 0o7777, 0o640)

    def test_same_content_destination_symlinks_are_replaced_without_touching_targets(
        self,
    ):
        self.apply()
        paths = {
            "certs/v1/cert.pem": (0o644, (0, 123)),
            "etc/caddy/Caddyfile": (0o640, (0, 123)),
            "usr/local/share/ca-certificates/orbit-managed-root-ca.crt": (
                0o644,
                (0, 0),
            ),
            "awake": (0o644, (0, 0)),
            "home/orbit/.orbit/certificates/speed/v1/key.pem": (0o600, None),
            "home/orbit/.orbit/certificates/speed/v1/cert.pem": (0o600, None),
        }
        outside = self.root / "outside"
        outside.mkdir()
        originals = {}
        for index, (name, (mode, _)) in enumerate(paths.items()):
            managed = self.root / name
            target = outside / str(index)
            target.write_bytes(managed.read_bytes())
            target.chmod(0o600)
            originals[target] = (target.read_bytes(), target.stat())
            managed.unlink()
            managed.symlink_to(target)

        original_run = self.tool_run

        def real_install(argv):
            if "install" not in argv:
                return original_run(argv)
            # Exercise GNU install's destination-link replacement, preserving its production flags.
            self.assertEqual(argv[argv.index("-m") + 1], "0600")
            self.assertEqual(argv[argv.index("-o") + 1], "orbit")
            self.assertEqual(argv[argv.index("-g") + 1], str(self.user.pw_gid))
            args = list(argv)
            for flag in ["-o", "-g"]:
                index = args.index(flag)
                del args[index : index + 2]
            subprocess.run(args, check=True, capture_output=True, timeout=5)

        with patch.object(self, "tool_run", side_effect=real_install):
            self.apply()
        for name, (mode, owner) in paths.items():
            with self.subTest(path=name):
                managed = self.root / name
                self.assertFalse(managed.is_symlink())
                self.assertTrue(managed.is_file())
                self.assertEqual(managed.stat().st_mode & 0o7777, mode)
                self.assertEqual(managed.stat().st_uid, os.getuid())
                self.assertEqual(managed.stat().st_gid, os.getgid())
                if owner is not None:
                    self.assertIn(
                        owner, self.owners
                    )  # Production root/caddy requests are checked unprivileged.
        for target, (data, metadata) in originals.items():
            self.assertEqual(target.read_bytes(), data)
            current = target.stat()
            self.assertEqual(
                (current.st_uid, current.st_gid, current.st_mode, current.st_mtime_ns),
                (
                    metadata.st_uid,
                    metadata.st_gid,
                    metadata.st_mode,
                    metadata.st_mtime_ns,
                ),
            )

    def test_failed_validation_keeps_live_bytes_and_metadata_and_cleans_candidate(self):
        live = self.root / "etc/caddy/Caddyfile"
        live.write_text("# previously validated file\n")
        live.chmod(0o640)
        before = live.stat()
        self.failure = "validate"
        with self.assertRaises(RuntimeError):
            self.apply()
        self.assertEqual(live.read_text(), "# previously validated file\n")
        current = live.stat()
        self.assertEqual(
            (
                current.st_ino,
                current.st_uid,
                current.st_gid,
                current.st_mode,
                current.st_mtime_ns,
            ),
            (
                before.st_ino,
                before.st_uid,
                before.st_gid,
                before.st_mode,
                before.st_mtime_ns,
            ),
        )
        self.assertEqual(list(live.parent.glob(".orbit-candidate-*")), [])
        self.assertFalse(any("reload" in c for c in self.calls))
        self.assertFalse((self.root / "awake").exists())
        self.failure = ""
        self.apply()
        self.assertTrue(any("reload" in c for c in self.calls))

    def test_site_publish_renames_the_validated_inode_with_final_metadata_before_interruption(
        self,
    ):
        groups = [gid for gid in os.getgroups() if gid != os.getgid()]
        if not groups:
            self.skipTest("supplementary group required to distinguish the Caddy group")
        self.physical_group = groups[0]
        live = self.root / "etc/caddy/Caddyfile"
        live.write_text("# old live file\n")
        live.chmod(0o640)
        os.chown(live, os.getuid(), self.physical_group)
        real_replace = os.replace

        def publish_and_interrupt(source, destination):
            if Path(destination) != live:
                return real_replace(source, destination)
            metadata = Path(source).stat()
            self.assertEqual(
                (
                    metadata.st_ino,
                    metadata.st_uid,
                    metadata.st_gid,
                    metadata.st_mode & 0o7777,
                ),
                self.validation_metadata,
            )
            self.assertEqual(metadata.st_gid, self.physical_group)
            self.assertEqual(metadata.st_mode & 0o7777, 0o640)
            real_replace(source, destination)
            raise RuntimeError("fixture interruption after rename")

        with (
            patch.object(SITE.os, "replace", side_effect=publish_and_interrupt),
            self.assertRaisesRegex(RuntimeError, "fixture interruption"),
        ):
            self.apply()
        current = live.stat()
        self.assertEqual(
            (current.st_ino, current.st_uid, current.st_gid, current.st_mode & 0o7777),
            self.validation_metadata,
        )
        self.assertEqual(live.read_text(), self.plan["caddy"][0]["content"])
        self.assertFalse(any("reload" in c for c in self.calls))
        self.assertEqual(list(live.parent.glob(".orbit-candidate-*")), [])

    def test_retry_after_reload_failure_completes_without_rewriting_public_files(self):
        self.failure = "reload"
        with self.assertRaises(RuntimeError):
            self.apply()
        stamp = (self.root / "etc/caddy/Caddyfile").stat().st_mtime_ns
        self.failure = ""
        self.apply()
        self.assertEqual((self.root / "etc/caddy/Caddyfile").stat().st_mtime_ns, stamp)
        self.assertTrue((self.root / "awake").exists())

    def test_php_only_publication_needs_no_caddy_group(self):
        plan = self.plan | {"leaf_indexes": [], "caddy": [], "trust": [], "awake": []}
        result = SITE.converge(
            plan,
            9,
            [],
            self.tool_run,
            self.root,
            lambda _: self.user,
            lambda _: self.fail("PHP-only publication must not resolve a Caddy group"),
        )
        self.assertEqual(result, {"changed": False})
        self.assertEqual(self.calls, [])

    def test_production_workspace_app_url_links_and_php_tasks_are_unchanged(self):
        play = yaml.safe_load(
            (ROOT / "swamp/ansible/route-create-site.yml").read_text()
        )[1]
        # Fixed production order; CI checkouts need no Git history or remote refs.
        self.assertEqual(
            [task["name"] for task in play["tasks"]],
            [
                "Read the account",
                "Set the Route access on this Node",
                "Link the shared environment files",
                "Write the PHP-FPM pool files",
                "Test the PHP-FPM configuration",
                "Reload PHP-FPM",
                "Converge certificate links trust and Caddy files",
                "Remove the run folder",
            ],
        )
        self.assertTrue(play["become"])
        self.assertFalse(play["gather_facts"])

    def test_csr_action_owns_folder_and_key_before_public_request_returns(self):
        flow = yaml.safe_load(
            (ROOT / "swamp/workflows/workflow-route-create-csr.yaml").read_text()
        )
        steps = {s["name"]: s for j in flow["jobs"] for s in j["steps"]}
        self.assertEqual(list(steps), ["csr", "sign"])
        self.assertEqual(
            steps["sign"]["dependsOn"],
            [{"step": "csr", "condition": {"type": "always"}}],
        )
        constants = [
            json.loads(s)
            for s in re.findall(
                r'"(?:[^"\\]|\\.)*"', steps["csr"]["task"]["inputs"]["argv"]
            )
        ]
        self.assertEqual(constants[:5], ["timeout", "30", "sh", "-eu", "-c"])
        self.assertNotIn('\\"', constants[5])
        binary = self.root / "bin"
        binary.mkdir()
        install = binary / "install"
        install.write_text(
            "#!"
            + sys.executable
            + '\nimport os,sys\na=sys.argv[1:]\nassert a[:5]==["-d","-o","root","-g","caddy"]\nos.execv("/usr/bin/install",["install","-d",*a[5:]])\n'
        )
        install.chmod(0o755)
        chown = binary / "chown"
        chown.write_text('#!/bin/sh\ntest "$1" = root:caddy\n')
        chown.chmod(0o755)
        leaf = self.root / "new-leaf"
        done = subprocess.run(
            constants + [str(leaf), "route-speed.fixture"],
            env=os.environ | {"PATH": str(binary) + ":" + os.environ["PATH"]},
            text=True,
            capture_output=True,
            check=False,
            timeout=5,
        )
        self.assertEqual(done.returncode, 0, done.stderr)
        self.assertEqual(leaf.stat().st_mode & 0o777, 0o750)
        self.assertEqual((leaf / "key.pem").stat().st_mode & 0o777, 0o640)
        self.assertIn("BEGIN CERTIFICATE REQUEST", done.stdout)
        self.assertNotIn("PRIVATE KEY", done.stdout)
        self.assertIn("certificate-sign", steps["sign"]["task"]["behaviorType"])

    @unittest.skipUnless(shutil.which("ansible-playbook"), "pinned Ansible required")
    def test_real_production_phase_keeps_access_app_url_php_links_and_reload(self):
        account = pwd.getpwuid(os.getuid())
        node = self.root / "production"
        node.mkdir()
        environment = node / ".env"
        environment.write_text("# production canary\nAPP_URL=old\nHAND_EDIT=kept\n")
        environment.chmod(0o640)
        shared = node / "shared.env"
        shared.write_text("fixture")
        pool = node / "pool.conf"
        binary = self.root / "bin"
        binary.mkdir()
        systemctl = binary / "systemctl"
        calls = self.root / "systemctl.jsonl"
        systemctl.write_text(
            "#!"
            + sys.executable
            + "\nimport sys,json\nfrom pathlib import Path\np=Path("
            + repr(str(calls))
            + ")\nwith p.open('a') as f:f.write(json.dumps(sys.argv[1:])+'\\n')\nif 'show' in sys.argv:print('Id=fixture-fpm.service\\nLoadState=loaded\\nActiveState=active\\nUnitFileState=enabled\\nCanReload=yes\\nNeedDaemonReload=no')\nelif 'is-enabled' in sys.argv:print('enabled')\n"
        )
        systemctl.chmod(0o755)
        acl = binary / "fixture-acl"
        acl.write_text("#!/bin/sh\ntouch " + str(node / "access-granted") + "\n")
        acl.chmod(0o755)
        plan = {
            "literal": "{{ should_never_be_evaluated }}",
            "nodes": {"9": {"account": account.pw_name}},
            "acl": [{"node_id": 9, "argv": [str(acl)]}],
            "url_lines": [
                {
                    "node_id": 9,
                    "path": str(environment),
                    "line": "APP_URL=https://fixture.invalid",
                }
            ],
            "links": [
                {
                    "node_id": 9,
                    "target": str(shared),
                    "path": str(node / "linked.env"),
                    "owner": account.pw_name,
                }
            ],
            "php_files": [
                {
                    "node_id": 9,
                    "path": str(pool),
                    "content": "group=__ORBIT_NODE_GROUP__\nhome=/__ORBIT_NODE_HOME__\n",
                }
            ],
            "php_tests": [{"node_id": 9, "argv": ["true"]}],
            "php_reloads": [{"node_id": 9, "unit": "fixture-fpm.service"}],
            "leaf_indexes": [],
            "trust": [],
            "caddy": [],
            "awake": [],
        }
        play = yaml.safe_load(
            (ROOT / "swamp/ansible/route-create-site.yml").read_text()
        )[1]
        play.update({"hosts": "localhost", "connection": "local", "become": False})
        play["vars"]["ansible_python_interpreter"] = sys.executable
        for task in play["tasks"]:
            if task.get("ansible.builtin.copy", {}).get("owner") == "root":
                self.assertEqual(task["ansible.builtin.copy"]["mode"], "0644")
                task["ansible.builtin.copy"].update(
                    owner=account.pw_name, group=str(os.getgid())
                )
        tasks = self.root / "tasks"
        tasks.mkdir()
        access = (ROOT / "swamp/ansible/tasks/route-access.yml").read_text()
        self.assertIn("become: true", access)
        (tasks / "route-access.yml").write_text(
            access.replace("become: true", "become: false")
        )
        library = self.root / "library"
        library.mkdir()
        # Execute the real module with its real argument validator. This PHP-only intent needs no Caddy group.
        code = SOURCE.read_text()
        (library / SOURCE.name).write_text(code)
        run = self.root / "run"
        run.mkdir()
        (run / "intent.json").write_text(json.dumps(plan))
        playfile = self.root / "production.json"
        playfile.write_text(json.dumps([play]))
        done = subprocess.run(
            ["ansible-playbook", "-i", "localhost,", str(playfile)],
            env=os.environ
            | {
                "ORBIT_RUN": str(run),
                "ORBIT_NODE_ID": "9",
                "ORBIT_PREPARED": "",
                "ORBIT_SIGNED": "",
                "ORBIT_INTENT_PATH": "",
                "ORBIT_INTENT_FIELD": "",
                "ANSIBLE_STDOUT_CALLBACK": "default",
                "ANSIBLE_COLLECTIONS_PATH": str(self.root / "empty"),
                "PATH": str(binary) + ":" + os.environ["PATH"],
            },
            capture_output=True,
            text=True,
            check=False,
            timeout=20,
        )
        self.assertEqual(done.returncode, 0, done.stdout + done.stderr)
        self.assertTrue((node / "access-granted").exists())
        self.assertEqual(
            environment.read_text(),
            "# production canary\nAPP_URL=https://fixture.invalid\nHAND_EDIT=kept\n",
        )
        self.assertEqual(environment.stat().st_mode & 0o777, 0o640)
        self.assertEqual((node / "linked.env").readlink(), shared)
        self.assertEqual(
            pool.read_text(),
            "group=" + str(account.pw_gid) + "\nhome=" + account.pw_dir + "\n",
        )
        commands = [json.loads(line) for line in calls.read_text().splitlines()]
        self.assertTrue(
            any("reload" in c and "fixture-fpm.service" in c for c in commands)
        )
        self.assertFalse(run.exists())

    def test_reused_input_is_read_only_and_child_cleanup_keeps_its_own_folder(self):
        flow = yaml.safe_load(
            (ROOT / "swamp/workflows/workflow-route-create-site.yaml").read_text()
        )
        steps = {s["name"]: s for j in flow["jobs"] for s in j["steps"]}
        env = steps["site"]["task"]["inputs"]["env"]
        self.assertIn("inputs.admitted.path", env["ORBIT_INTENT_PATH"])
        self.assertNotIn("inputs.admitted", env["ORBIT_RUN"])
        self.assertIn("inputs.admitted", steps["input"]["guard"])
        self.assertIn(
            {"type": "skipped"},
            steps["site"]["dependsOn"][0]["condition"]["conditions"],
        )
        self.assertEqual(
            steps["cleanup"]["task"]["inputs"]["env"]["ORBIT_RUN"], env["ORBIT_RUN"]
        )
        source = (ROOT / "swamp/ansible/route-create-site.yml").read_text()
        self.assertIn("route_input.route", source)
        self.assertIn("ORBIT_INTENT_PATH", source)
        self.assertEqual(
            yaml.safe_load(source)[1]["tasks"][-1]["ansible.builtin.file"]["path"],
            "{{ lookup('ansible.builtin.env', 'ORBIT_RUN') }}",
        )


if __name__ == "__main__":
    unittest.main()
