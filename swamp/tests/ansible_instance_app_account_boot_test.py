"""The container phase's install of the app-account swap file survives a container that is still booting.

`incus start` returns while the container's systemd still boots, and its tmp.mount then puts a new tmpfs over /tmp: a file
written there before the mount is hidden, and the next step fails with "cannot stat '/tmp/...'". Here the pinned Ansible runs the
phase's own task on this machine (an empty `as_root`), and a stand-in for the mount empties the temporary folder between the
write and the install.
"""

import os
import shutil
import stat
import subprocess
import tempfile
import unittest
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[2]
PHASE = ROOT / "swamp/ansible/tasks/instance-container-phase.yml"
TASK = "Move the app account to www-data"


class AppAccountBoot(unittest.TestCase):
    def setUp(self):
        temp = tempfile.TemporaryDirectory(prefix="orbit-app-account-")
        self.addCleanup(temp.cleanup)
        self.root = Path(temp.name)
        self.tmp = self.root / "tmp"
        self.tmp.mkdir()
        self.target = self.root / "usr/local/libexec/orbit-app-user"
        shims = self.root / "bin"
        shims.mkdir()
        # The boot's tmp.mount, between the write of the scratch copy and its install: what mktemp put in /tmp is gone. Only its
        # names go, as Ansible's own module payload sits in the same folder.
        (shims / "cmp").write_text(f'#!/bin/sh\nrm -f "$TMPDIR"/tmp.*\nexec {shutil.which("cmp")} "$@"\n')
        # Root's owner flags are dropped for this unprivileged run; `INSTALL_FAILS` stands for a failed install.
        (shims / "install").write_text(
            '#!/bin/sh\n[ -n "$INSTALL_FAILS" ] && exit 1\n'
            'for a in "$@"; do case "$a" in -o|-g) skip=1 ;; *) [ -n "$skip" ] && skip= || set -- "$@" "$a" ;; esac; shift; done\n'
            f'exec {shutil.which("install")} "$@"\n'
        )
        for shim in shims.iterdir():
            shim.chmod(0o755)
        self.executable = shutil.which("ansible-playbook")
        self.assertIsNotNone(self.executable, "Pinned Ansible must be on PATH")
        self.env = {**os.environ, "HOME": str(self.root), "ANSIBLE_LOCAL_TEMP": str(self.root / "ansible-local"),
                    "ANSIBLE_REMOTE_TEMP": str(self.root / "ansible-remote"), "ANSIBLE_NOCOLOR": "1"}
        self.task = next(t for t in yaml.safe_load(PHASE.read_text()) if t.get("name") == TASK)

    def run_task(self, content, install_fails=False):
        """The phase's task as the pinned Ansible runs it; returns the play's result and the task's registered result."""
        result = self.root / "result.yml"
        play = [{
            "hosts": "localhost", "gather_facts": False, "connection": "local",
            "vars": {"phase": "container", "as_root": [],
                     "container": {"app_account": {"path": str(self.target), "content": content}}},
            "tasks": [{
                "block": [self.task],
                "environment": {"PATH": f"{self.root / 'bin'}:{os.environ['PATH']}", "TMPDIR": str(self.tmp),
                                "INSTALL_FAILS": "1" if install_fails else ""},
                "always": [{"ansible.builtin.copy": {"content": "{{ app_user_name | to_nice_yaml }}", "dest": str(result)}}],
            }],
        }]
        (self.root / "play.yml").write_text(yaml.safe_dump(play, sort_keys=False))
        done = subprocess.run([self.executable, "-i", "localhost,", str(self.root / "play.yml")], cwd=self.root, env=self.env,
                              capture_output=True, text=True, check=False)
        return done, yaml.safe_load(result.read_text())

    def leftovers(self):
        return sorted(p.name for p in self.target.parent.iterdir()) if self.target.parent.exists() else []

    def test_the_swap_file_installs_and_runs_while_tmp_is_replaced(self):
        content = "#!/bin/sh\necho 'orbit-app-user: moved to www-data (restarted: none)'\n"
        done, task = self.run_task(content)

        self.assertEqual(done.returncode, 0, done.stdout + done.stderr)
        self.assertEqual((task["rc"], task["stderr"]), (0, ""))
        self.assertTrue(task["changed"])
        self.assertEqual(self.target.read_text(), content)
        self.assertEqual(stat.S_IMODE(self.target.stat().st_mode), 0o755)
        self.assertEqual(self.leftovers(), ["orbit-app-user"])

    def test_the_same_file_is_not_written_again_and_a_new_one_is(self):
        first = "#!/bin/sh\necho 'orbit-app-user: already www-data'\n"
        self.assertEqual(self.run_task(first)[0].returncode, 0)
        written = self.target.stat().st_ino

        done, task = self.run_task(first)
        self.assertEqual(done.returncode, 0, done.stdout + done.stderr)
        self.assertEqual((task["stdout"], task["changed"]), ("orbit-app-user: already www-data", False))
        self.assertEqual(self.target.stat().st_ino, written)

        changed = "#!/bin/sh\necho two\n"
        done, task = self.run_task(changed)
        self.assertEqual((done.returncode, task["stdout"]), (0, "two"), done.stdout + done.stderr)
        self.assertEqual(self.target.read_text(), changed)
        self.assertEqual(self.leftovers(), ["orbit-app-user"])

    def test_a_failed_install_leaves_no_scratch_copy_and_the_rerun_installs(self):
        content = "#!/bin/sh\necho one\n"
        done, task = self.run_task(content, install_fails=True)
        self.assertNotEqual(done.returncode, 0)
        self.assertEqual(task["rc"], 1)
        self.assertEqual(self.leftovers(), [])

        done, task = self.run_task(content)
        self.assertEqual((done.returncode, task["stdout"]), (0, "one"), done.stdout + done.stderr)
        self.assertEqual(self.leftovers(), ["orbit-app-user"])


if __name__ == "__main__":
    unittest.main()
