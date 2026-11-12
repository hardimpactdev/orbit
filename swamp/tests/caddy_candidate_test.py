"""Unprivileged invalid/valid candidate proof for every changed Caddy writer."""

import grp
import json
import os
import pwd
import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[2]


def find_named(value, name):
    if isinstance(value, dict):
        if value.get("name") == name:
            return value
        for child in value.values():
            found = find_named(child, name)
            if found is not None:
                return found
    elif isinstance(value, list):
        for child in value:
            found = find_named(child, name)
            if found is not None:
                return found
    return None


class CaddyCandidateTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="orbit-caddy-candidate-")
        self.addCleanup(self.temp.cleanup)
        self.folder = Path(self.temp.name)
        self.config = self.folder / "etc/caddy"
        self.config.mkdir(parents=True)
        self.live = self.config / "Caddyfile"
        self.publish_group = os.getgid()
        self.old = b"# previously valid\n"
        self.live.write_bytes(self.old)
        self.live.chmod(0o640)
        (self.config / "relative.conf").write_text("fixture include")
        self.bin = self.folder / "bin"
        self.bin.mkdir()
        self.calls = self.folder / "calls"
        caddy = self.bin / "caddy"
        caddy.write_text(
            "#!/usr/bin/env python3\n"
            "import sys\nfrom pathlib import Path\n"
            "p=Path(sys.argv[sys.argv.index('--config')+1])\n"
            "assert p.name != 'Caddyfile', 'must validate a candidate'\n"
            "assert p.parent == Path(" + repr(str(self.config)) + ")\n"
            "assert (p.stat().st_mode & 0o777) in (0o640, 0o644)\n"
            "import json,os\n"
            "m=p.stat()\n"
            "Path("
            + repr(str(self.folder / "validated.json"))
            + ").write_text(json.dumps([m.st_ino,m.st_uid,m.st_gid,m.st_mode&0o777]))\n"
            "s=p.read_text()\n"
            "assert 'INVALID' not in s, 'invalid candidate'\n"
            "if 'import relative.conf' in s: assert (p.parent/'relative.conf').read_text()=='fixture include'\n"
        )
        caddy.chmod(0o755)
        install = self.bin / "install"
        install.write_text(
            "#!/usr/bin/env python3\nimport sys,os\n"
            "a=sys.argv[1:]\nassert a[:6]==['-m','0640','-o','root','-g','caddy']\n"
            "os.execv('/usr/bin/install',['install','-m','0640',*a[6:]])\n"
        )
        install.chmod(0o755)
        cat = self.bin / "cat"
        cat.write_text(
            "#!/usr/bin/env python3\nimport os,sys\n"
            "m=os.fstat(1)\nassert m.st_mode&0o777==0o640\n"
            "sys.stdout.buffer.write(sys.stdin.buffer.read())\n"
        )
        cat.chmod(0o755)
        chown = self.bin / "chown"
        chown.write_text('#!/bin/sh\n[ "$1" = root:caddy ]\n')
        chown.chmod(0o755)
        mv = self.bin / "mv"
        mv.write_text(
            "#!/usr/bin/env python3\nimport json,os,sys\nfrom pathlib import Path\n"
            "a,b=map(Path,sys.argv[-2:]);m=a.stat()\n"
            "v=[m.st_ino,m.st_uid,m.st_gid,m.st_mode&0o777]\n"
            "assert v==json.loads(Path("
            + repr(str(self.folder / "validated.json"))
            + ").read_text())\n"
            "os.replace(a,b)\n"
            "Path("
            + repr(str(self.folder / "published.json"))
            + ").write_text(json.dumps(v))\n"
            "sys.exit(97 if Path("
            + repr(str(self.folder / "interrupt-after-rename"))
            + ").exists() else 0)\n"
        )
        mv.chmod(0o755)
        runuser = self.bin / "runuser"
        runuser.write_text(
            '#!/bin/sh\n[ "$1 $2 $3 $4" = \'-u caddy -- caddy\' ] || exit 91\nshift 3\nexec "$@"\n'
        )
        runuser.chmod(0o755)
        systemctl = self.bin / "systemctl"
        systemctl.write_text(
            "#!/bin/sh\nprintf '%s\\n' \"$*\" >> " + str(self.calls) + "\n"
        )
        systemctl.chmod(0o755)

    def source(self, name):
        return yaml.safe_load((ROOT / name).read_text())

    def native(self, workflow, write_step, validate_step, reload_step):
        flow = self.source("swamp/workflows/workflow-" + workflow + ".yaml")
        steps = {s["name"]: s for j in flow["jobs"] for s in j["steps"]}
        write = steps[write_step]["task"]
        destination = (
            write["globalArgs"]["path"]
            if write["behaviorType"] == "@swamp/cfgmgmt/file"
            else write["inputs"]["argv"][-1]
        )
        self.assertIn("/etc/caddy/.orbit-candidate-", destination)
        self.assertNotEqual(destination, "/etc/caddy/Caddyfile")
        validate = steps[validate_step]
        self.assertTrue(validate["writes"])
        self.assertTrue(validate["task"]["inputs"]["sudo"])
        dependencies = steps[reload_step]["dependsOn"]
        self.assertTrue(any(d["step"] == validate_step for d in dependencies))
        # Older callers use always + a failed-step guard; the standalone writer uses succeeded.
        if any(
            d["step"] == validate_step and d["condition"]["type"] == "always"
            for d in dependencies
        ):
            self.assertIn('status == "failed"', steps[reload_step]["guard"])
        argv = list(validate["task"]["inputs"]["argv"])
        candidate = self.config / ".orbit-candidate-fixture"
        argv[-2], argv[-1] = str(candidate), str(self.live)
        argv[5] = argv[5].replace("/usr/bin/caddy", "caddy")
        env = os.environ | {"PATH": str(self.bin) + ":" + os.environ["PATH"]}
        for invalid in [True, False]:
            with self.subTest(invalid=invalid):
                text = (
                    "http://127.0.0.1:22999 {\n INVALID_DIRECTIVE\n}\n"
                    if invalid
                    else "import relative.conf\n# valid replacement\n"
                )
                if not invalid:
                    outside = self.folder / "outside-native"
                    outside.write_text(
                        text
                    )  # Matching bytes must still replace a link.
                    outside.chmod(0o600)
                    self.live.unlink()
                    self.live.symlink_to(outside)
                stage = list(write["inputs"]["argv"])
                stage[-1] = str(candidate)
                index = stage.index("-c") + 1
                stage[index] = (
                    stage[index]
                    .replace("/usr/bin/install", "install")
                    .replace("/usr/bin/cat", "cat")
                )
                staged = subprocess.run(
                    stage,
                    input=text.encode(),
                    env=env,
                    capture_output=True,
                    timeout=5,
                    check=False,
                )
                self.assertEqual(staged.returncode, 0, staged.stderr)
                self.assertEqual(candidate.stat().st_mode & 0o777, 0o640)
                metadata = self.live.stat()
                done = subprocess.run(
                    argv, env=env, capture_output=True, timeout=5, check=False
                )
                if invalid:
                    self.assertNotEqual(done.returncode, 0)
                    self.assertEqual(self.live.read_bytes(), self.old)
                    self.assertEqual(
                        (
                            self.live.stat().st_ino,
                            self.live.stat().st_uid,
                            self.live.stat().st_gid,
                            self.live.stat().st_mode,
                            self.live.stat().st_mtime_ns,
                        ),
                        (
                            metadata.st_ino,
                            metadata.st_uid,
                            metadata.st_gid,
                            metadata.st_mode,
                            metadata.st_mtime_ns,
                        ),
                    )
                    self.assertFalse(self.calls.exists())
                else:
                    self.assertEqual(done.returncode, 0, done.stderr)
                    self.assertEqual(self.live.read_text(), text)
                    self.assertFalse(self.live.is_symlink())
                    self.assertEqual(outside.read_text(), text)
                    self.assertEqual(outside.stat().st_mode & 0o777, 0o600)
                    subprocess.run(
                        ["systemctl", "reload", "caddy"], env=env, check=True
                    )
                    self.assertEqual(self.calls.read_text(), "reload caddy\n")
                self.assertFalse(candidate.exists())
                self.assertEqual(self.live.stat().st_mode & 0o777, 0o640)

    def test_native_whole_node_caddy_publication(self):
        self.native(
            "operation-route-caddy",
            "Write the Caddyfile",
            "Validate the Caddyfile",
            "Reload Caddy",
        )

    def test_native_delta_used_by_all_three_migrate_caddy_passes(self):
        self.native("operation-route-caddy-delta", "apply-config", "validate", "reload")

    def test_native_ingress_publication(self):
        self.native(
            "route-create-ingress", "caddyfile", "caddy-validate", "caddy-reload"
        )

    def rewrite(self, value):
        if isinstance(value, dict):
            return {
                key: (
                    pwd.getpwuid(os.getuid()).pw_name
                    if key == "owner"
                    else grp.getgrgid(self.publish_group).gr_name
                    if key == "group"
                    else self.rewrite(child)
                )
                for key, child in value.items()
            }
        if isinstance(value, list):
            return [self.rewrite(child) for child in value]
        if isinstance(value, str):
            return value.replace("/etc/caddy", str(self.config))
        return value

    def ansible(self, source, task_name, context, interrupt=False):
        if not shutil.which("ansible-playbook"):
            self.skipTest("pinned Ansible required")
        original = find_named(self.source(source), task_name)
        self.assertIsNotNone(original)
        task = self.rewrite(original)
        if task.get("ansible.builtin.import_tasks") == "caddy-publish.yml":
            task["ansible.builtin.import_tasks"] = "tasks/caddy-publish.yml"
        helper = self.source("swamp/ansible/tasks/caddy-publish.yml")
        self.assertEqual(helper[0]["ansible.builtin.tempfile"]["path"], "/etc/caddy")
        write = find_named(helper, "Write the restricted Caddy candidate")[
            "ansible.builtin.copy"
        ]
        self.assertEqual(write["owner"], "root")
        self.assertEqual(write["mode"], "{{ caddy_mode | default('0640') }}")
        publish = find_named(helper, "Atomically publish the validated Caddyfile")
        self.assertEqual(
            publish["ansible.builtin.command"]["argv"][:3], ["mv", "-Tf", "--"]
        )
        is_site = "orbit_route_site" in task
        if is_site:
            library = self.folder / "library"
            library.mkdir()
            module = (ROOT / "swamp/ansible/library/orbit_route_site.py").read_text()
            module = module.replace(
                'root=Path("/")', "root=Path(" + repr(str(self.folder)) + ")"
            )
            module = module.replace(
                "group_lookup=grp.getgrnam,",
                "group_lookup=lambda name: grp.getgrnam("
                + repr(grp.getgrgid(self.publish_group).gr_name)
                + "),",
            )
            module = module.replace(
                "os.fchown(stream.fileno(), uid, gid)",
                "os.fchown(stream.fileno(), os.getuid(), gid)",
            )
            (library / "orbit_route_site.py").write_text(module)
        helpers = self.folder / "tasks"
        helpers.mkdir()
        (helpers / "caddy-publish.yml").write_text(
            yaml.safe_dump(self.rewrite(helper), sort_keys=False)
        )
        play = {
            "hosts": "localhost",
            "connection": "local",
            "gather_facts": False,
            "vars": context,
            "tasks": [task],
            "handlers": [
                {
                    "name": "Reload Caddy",
                    "ansible.builtin.command": {
                        "argv": ["systemctl", "reload", "caddy"]
                    },
                    "changed_when": True,
                }
            ],
        }
        env = os.environ | {
            "PATH": str(self.bin) + ":" + os.environ["PATH"],
            "ANSIBLE_LOCAL_TEMP": str(self.folder / "local"),
            "ANSIBLE_REMOTE_TEMP": str(self.folder / "remote"),
            "ANSIBLE_NOCOLOR": "1",
        }
        for invalid in [False] if interrupt else [True, False]:
            with self.subTest(invalid=invalid):
                value = (
                    "http://127.0.0.1:22999 {\n INVALID_DIRECTIVE\n}\n"
                    if invalid
                    else "import relative.conf\n# valid replacement\n"
                )
                play["vars"]["intent"] = {
                    "nodes": {"9": {"account": pwd.getpwuid(os.getuid()).pw_name}},
                    "caddy": [{"node_id": 9, "content": value}],
                }
                play["vars"]["plan"] = {"caddy": {"content": value}}
                play["vars"]["front_certificate"] = {
                    "stdout": json.dumps(
                        {
                            "directory": str(
                                self.config / ("INVALID" if invalid else "certs")
                            )
                        }
                    )
                }
                filename = self.folder / "play.yml"
                filename.write_text(yaml.safe_dump([play], sort_keys=False))
                if not invalid:
                    outside = self.folder / "outside-ansible"
                    outside.write_bytes(self.old)
                    outside.chmod(0o600)
                    self.live.unlink()
                    self.live.symlink_to(outside)
                before = self.live.stat()
                done = subprocess.run(
                    ["ansible-playbook", "-i", "localhost,", str(filename)],
                    cwd=self.folder,
                    check=False,
                    env=env,
                    capture_output=True,
                    text=True,
                    timeout=25,
                )
                if interrupt:
                    self.assertNotEqual(done.returncode, 0, done.stdout)
                    self.assertFalse(self.calls.exists())
                    validated = json.loads((self.folder / "validated.json").read_text())
                    published = json.loads((self.folder / "published.json").read_text())
                    metadata = self.live.stat()
                    self.assertEqual(published, validated)
                    self.assertEqual(
                        published,
                        [
                            metadata.st_ino,
                            metadata.st_uid,
                            metadata.st_gid,
                            metadata.st_mode & 0o777,
                        ],
                    )
                    self.assertEqual(metadata.st_gid, self.publish_group)
                    self.assertEqual(metadata.st_mode & 0o777, 0o640)
                    self.assertNotEqual(self.live.read_bytes(), self.old)
                elif invalid:
                    self.assertNotEqual(done.returncode, 0, done.stdout)
                    self.assertIn(
                        "Route certificate or site publication failed."
                        if is_site
                        else "invalid candidate",
                        done.stdout,
                    )
                    self.assertEqual(self.live.read_bytes(), self.old)
                    self.assertEqual(
                        (
                            self.live.stat().st_ino,
                            self.live.stat().st_uid,
                            self.live.stat().st_gid,
                            self.live.stat().st_mode,
                            self.live.stat().st_mtime_ns,
                        ),
                        (
                            before.st_ino,
                            before.st_uid,
                            before.st_gid,
                            before.st_mode,
                            before.st_mtime_ns,
                        ),
                    )
                    self.assertFalse(self.calls.exists())
                else:
                    self.assertEqual(done.returncode, 0, done.stdout + done.stderr)
                    self.assertNotEqual(self.live.read_bytes(), self.old)
                    self.assertEqual(outside.read_bytes(), self.old)
                    self.assertEqual(outside.stat().st_mode & 0o777, 0o600)
                    self.assertEqual(self.calls.read_text(), "reload caddy\n")
                self.assertEqual(list(self.config.glob(".orbit-candidate-*")), [])
                self.assertFalse(self.live.is_symlink())

    def test_validated_inode_has_final_metadata_at_interrupted_rename(self):
        groups = [gid for gid in os.getgroups() if gid != os.getgid()]
        if not groups:
            self.skipTest("supplementary group required to distinguish the Caddy group")
        self.publish_group = groups[0]
        (self.folder / "interrupt-after-rename").touch()
        self.ansible(
            "swamp/ansible/route-withdraw.yml",
            "Publish the Caddyfile",
            {},
            interrupt=True,
        )

    def test_shape_live_proof_stages_validates_and_renames(self):
        text = (ROOT / "swamp/proof/spike/shape-live").read_text()
        script = (
            text.split('incus exec "$node" -- sh -eu -c ')[1].split(" <")[0].strip()
        )
        self.assertTrue(script.startswith("'") and script.endswith("'"))
        script = script[1:-1].replace("/etc/caddy", str(self.config))
        env = os.environ | {"PATH": str(self.bin) + ":" + os.environ["PATH"]}
        for invalid in [True, False]:
            value = (
                "INVALID"
                if invalid
                else "import relative.conf\n# valid proof fixture\n"
            )
            done = subprocess.run(
                ["sh", "-eu", "-c", script],
                input=value.encode(),
                env=env,
                capture_output=True,
                timeout=5,
                check=False,
            )
            if invalid:
                self.assertNotEqual(done.returncode, 0)
                self.assertEqual(self.live.read_bytes(), self.old)
                self.assertFalse(self.calls.exists())
            else:
                self.assertEqual(done.returncode, 0, done.stderr)
                self.assertEqual(self.live.read_text(), value)
            self.assertEqual(list(self.config.glob(".orbit-shape-candidate.*")), [])

    def test_ansible_route_site(self):
        self.ansible(
            "swamp/ansible/route-create-site.yml",
            "Converge certificate links trust and Caddy files",
            {"node_id": 9, "signed": []},
        )

    def test_ansible_route_withdrawal(self):
        self.ansible("swamp/ansible/route-withdraw.yml", "Publish the Caddyfile", {})

    def test_ansible_bootstrap_swamp_front(self):
        self.ansible("swamp/ansible/tasks/gateway-swamp.yml", "Write the front", {})


if __name__ == "__main__":
    unittest.main()
