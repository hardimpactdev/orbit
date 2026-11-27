"""The container phase's install of the app-account swap file survives a container that is still booting.

`incus start` returns while the container's systemd still boots, and its tmp.mount then puts a new tmpfs over /tmp: a file
written there before the mount is hidden, and the next step fails with "cannot stat '/tmp/...'". Here the wrapper runs as the
phase renders it, and a stand-in for the mount empties the temporary folder between the write and the install.
"""

import ast
import os
import shutil
import stat
import subprocess
import tempfile
import unittest
from pathlib import Path

import jinja2
import yaml

ROOT = Path(__file__).resolve().parents[2]
PHASE = ROOT / "swamp/ansible/tasks/instance-container-phase.yml"


def wrapper(path):
    """The phase's argv for root's swap file, without the `incus exec` prefix."""
    task = next(t for t in yaml.safe_load(PHASE.read_text()) if t.get("name") == "Move the app account to www-data")
    rendered = jinja2.Environment().from_string(task["ansible.builtin.command"]["argv"]).render(
        as_root=[], container={"app_account": {"path": str(path)}}
    )
    return ast.literal_eval(rendered)


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
        # The boot's tmp.mount, between the write of the scratch copy and its install: what was in /tmp is gone.
        (shims / "cmp").write_text(f'#!/bin/sh\nrm -rf "$TMPDIR"/* "$TMPDIR"/.[!.]*\nexec {shutil.which("cmp")} "$@"\n')
        # Root's owner flags are dropped for this unprivileged run; `INSTALL_FAILS` stands for a failed install.
        (shims / "install").write_text(
            "#!/bin/sh\n[ -n \"$INSTALL_FAILS\" ] && exit 1\n"
            'for a in "$@"; do case "$a" in -o|-g) skip=1 ;; *) [ -n "$skip" ] && skip= || set -- "$@" "$a" ;; esac; shift; done\n'
            f'exec {shutil.which("install")} "$@"\n'
        )
        for shim in shims.iterdir():
            shim.chmod(0o755)
        self.env = {"PATH": f"{shims}:{os.environ['PATH']}", "TMPDIR": str(self.tmp)}

    def run_wrapper(self, content, **env):
        return subprocess.run(wrapper(self.target), check=False, input=content, capture_output=True, text=True, env={**self.env, **env})

    def test_the_swap_file_installs_and_runs_while_tmp_is_replaced(self):
        content = "#!/bin/sh\necho 'orbit-app-user: already www-data'\n"
        done = self.run_wrapper(content)

        self.assertEqual(done.returncode, 0, done.stderr)
        self.assertEqual(done.stdout, "orbit-app-user: already www-data\n")
        self.assertEqual(self.target.read_text(), content)
        self.assertEqual(stat.S_IMODE(self.target.stat().st_mode), 0o755)
        self.assertEqual(sorted(p.name for p in self.target.parent.iterdir()), ["orbit-app-user"])

    def test_the_same_file_is_not_written_again_and_a_new_one_is(self):
        first = "#!/bin/sh\necho one\n"
        self.assertEqual(self.run_wrapper(first).returncode, 0)
        written = self.target.stat().st_ino

        again = self.run_wrapper(first)
        self.assertEqual((again.returncode, again.stdout), (0, "one\n"))
        self.assertEqual(self.target.stat().st_ino, written)

        changed = self.run_wrapper("#!/bin/sh\necho two\n")
        self.assertEqual((changed.returncode, changed.stdout), (0, "two\n"))
        self.assertEqual(self.target.read_text(), "#!/bin/sh\necho two\n")
        self.assertEqual(sorted(p.name for p in self.target.parent.iterdir()), ["orbit-app-user"])

    def test_a_failed_install_leaves_no_scratch_copy(self):
        done = self.run_wrapper("#!/bin/sh\necho one\n", INSTALL_FAILS="1")

        self.assertNotEqual(done.returncode, 0)
        self.assertEqual(list(self.target.parent.iterdir()), [])


if __name__ == "__main__":
    unittest.main()
