"""Execute shipped removal actions with fake package-manager subprocesses."""
import contextlib
import io
import json
import types
import unittest
from pathlib import Path
from unittest.mock import patch

ROOT = Path(__file__).parent


def run(name, replies=()):
    data = {"package": "vendor/package" if name == "composer" else "hello", "user": "orbit",
            "before": {"prefix": "/prefix", "scope_path": "/scope"}}
    source = (ROOT / "mutations" / (name + ".py")).read_text()
    if name.startswith("apt"):
        source = source.replace("REMOVE_INPUT = None", "REMOVE_INPUT = json.loads(" + repr(json.dumps(data)) + ")")
    else:
        source = (ROOT / "queries/support.py").read_text() + "\n" + source.replace("from support import INPUT, query\n", "")
        source = source.replace("QUERY_INPUT = None", "QUERY_INPUT = " + repr(json.dumps(data)))
    responses = [types.SimpleNamespace(stdout=value) for value in replies] or [types.SimpleNamespace(stdout="")]
    account = types.SimpleNamespace(pw_uid=2000, pw_name="orbit", pw_dir="/home/orbit")
    output = io.StringIO()
    with patch("pwd.getpwnam", return_value=account), patch("os.geteuid", return_value=2000), \
            patch("subprocess.run", side_effect=responses) as command, contextlib.redirect_stdout(output):
        exec(compile(source, str(ROOT / "mutations" / (name + ".py")), "exec"), {})  # noqa: S102 -- execute shipped assets against fake subprocesses
    return command.call_args, json.loads(output.getvalue())


class RemovalScripts(unittest.TestCase):
    def test_apt_removes_exact_selected_package_with_native_dependency_refusal(self):
        call, result = run("apt")
        self.assertEqual(call.args[0], ["/usr/bin/dpkg", "--remove", "--", "hello"])
        self.assertTrue(call.kwargs["check"])
        self.assertEqual(result, {"ready": True})

    def test_apt_plan_preserves_other_packages_and_normalizes_multiarch(self):
        call, result = run("apt_plan", ["Remv hello:amd64 [1]\nRemv shared [2]\nRemv hello:amd64 [1]\n"])
        self.assertEqual(call.args[0], ["/usr/bin/apt-get", "--simulate", "remove", "--", "hello"])
        self.assertEqual(result, {"packages": ["hello", "shared"]})
        for row in ("Remv", "Remv --foreign [1]", "Remv hello/foreign [1]"):
            with self.subTest(row=row), self.assertRaises(ValueError):
                run("apt_plan", [row])

    def test_other_managers_use_only_exact_root_and_observed_scope_without_sudo(self):
        expected = {"composer": ["/usr/bin/composer", "global", "remove", "vendor/package", "--no-interaction", "--no-ansi", "--no-progress", "--no-audit", "--with-all-dependencies"],
                    "vp": ["/scope/bin/vp", "remove", "-g", "hello"],
                    "brew": ["/prefix/bin/brew", "uninstall", "--formula", "homebrew/core/hello"],
                    "brew_cask": ["/prefix/bin/brew", "uninstall", "--cask", "hello"]}
        for manager, argv in expected.items():
            with self.subTest(manager=manager):
                call, result = run(manager)
                self.assertEqual(call.args[0], argv)
                self.assertEqual(call.kwargs["cwd"], "/home/orbit")
                self.assertEqual(call.kwargs["env"]["HOME"], "/home/orbit")
                self.assertEqual(result, {"ready": True})
                if manager.startswith("brew"):
                    self.assertEqual(call.kwargs["env"]["HOMEBREW_NO_AUTOREMOVE"], "1")
                    self.assertEqual(call.kwargs["env"]["HOMEBREW_NO_AUTO_UPDATE"], "1")
                if manager == "vp":
                    self.assertEqual(call.kwargs["env"]["VP_HOME"], "/scope")
                if manager == "composer":
                    self.assertEqual(call.kwargs["env"]["COMPOSER_HOME"], "/opt/orbit/composer")


if __name__ == "__main__":
    unittest.main()
