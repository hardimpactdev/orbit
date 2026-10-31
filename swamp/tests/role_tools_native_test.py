"""Enrollment tool intent reaches shipped one-action files through unmodified official SSH."""

import hashlib
import importlib.util
import io
import json
import os
import shutil
import subprocess
import sys
import tarfile
import unittest
import zipfile
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location(
    "native_harness", ROOT / "tests/native_harness.py"
)
harness = importlib.util.module_from_spec(spec)
spec.loader.exec_module(harness)


class RoleTools(harness.NativeTestCase):
    native_timeout = 120

    def setUp(self):
        super().setUp()
        self.copy_behaviors("operations/node-bootstrap", "operations/node-role-command")
        bootstrap = self.repo / "extensions/behaviors/operations/node-bootstrap"
        for file in bootstrap.glob("*.ts"):
            if file.name != "role_tool_assets.ts":
                file.unlink()
        roles = self.repo / "extensions/behaviors/operations/node-role-command"
        for file in roles.glob("*.ts"):
            if file.name != "assets.ts":
                file.unlink()
        official = self.repo / ".swamp/pulled-extensions/@swamp/ssh"
        official.parent.mkdir(parents=True)
        shutil.copytree(ROOT / ".swamp/pulled-extensions/@swamp/ssh", official)
        inventory = json.loads(
            (ROOT / "extensions/models/upstream_extensions.json").read_text()
        )
        path = self.repo / "extensions/models/upstream_extensions.json"
        path.parent.mkdir(parents=True)
        path.write_text(json.dumps({"@swamp/ssh": inventory["@swamp/ssh"]}))
        self.target = self.repo / "target"
        self.target.mkdir()
        self.tools = self.repo / "fake-binaries"
        self.tools.mkdir()
        self.native_env["PATH"] = str(self.tools) + ":" + os.environ["PATH"]
        for name, code in {
            "ssh": """import shlex,subprocess,sys
args=sys.argv[1:]
if '-M' in args or '-O' in args: raise SystemExit(0)
argv=shlex.split(args[-1])
if argv[:3]==['sudo','-n','--']: argv=argv[3:]
r=subprocess.run(argv,input=sys.stdin.buffer.read(),capture_output=True)
sys.stdout.buffer.write(r.stdout);sys.stderr.buffer.write(r.stderr);raise SystemExit(r.returncode)
""",
            "scp": """import shutil,sys
from pathlib import Path
fault=Path(FAULT)
if fault.exists():fault.unlink();raise SystemExit(1)
src=Path(sys.argv[-2]);dst=Path(sys.argv[-1].split(':',1)[1])
shutil.copytree(src,dst/src.name,dirs_exist_ok=True)
""".replace("FAULT", repr(str(self.repo / "copy-fault"))),
            "sudo": """import subprocess,sys
args=sys.argv[1:];args=args[args.index('--')+1:]
raise SystemExit(subprocess.run(args).returncode)
""",
            "apt-get": """import json,sys
from pathlib import Path
root=Path(TARGET)
if (root/'fail-apt').exists():raise SystemExit(1)
if 'install' in sys.argv:
    state={a.split('=',1)[0]:a.split('=',1)[1] for a in sys.argv[sys.argv.index('--')+1:]}
    (root/'packages.json').write_text(json.dumps(state))
    with (root/'apt-installs').open('a') as stream:stream.write('installed\\n')
""".replace("TARGET", repr(str(self.target))),
            "dpkg-query": """import json,sys
from pathlib import Path
root=Path(TARGET)
if sys.argv[-1]=='composer' and (root/'fail-composer').exists():
    counter=root/'composer-queries';n=int(counter.read_text())+1 if counter.exists() else 1;counter.write_text(str(n))
    if n==2:(root/'fail-composer').unlink();print('wrong-version',end='');raise SystemExit(0)
print(json.loads(root.joinpath('packages.json').read_text())[sys.argv[-1]],end='')
""".replace("TARGET", repr(str(self.target))),
            "apparmor_parser": "raise SystemExit(0)\n",
        }.items():
            path = self.tools / name
            path.write_text("#!" + sys.executable + "\n" + code)
            path.chmod(0o755)
        (self.target / "bin").mkdir()
        (self.target / "home").mkdir()
        (self.target / "apparmor").mkdir()
        (self.target / "os-release").write_text(
            "ID=ubuntu\nVERSION_CODENAME=resolute\n"
        )
        self.artifacts = self.repo / "public-artifacts"
        self.artifacts.mkdir()
        versions = {
            "node": "24.22.1",
            "vp-cli": "1.4.0",
            "vp": "1.4.0",
            "pnpm": "12.11.2",
            "bun": "1.4.3",
        }
        scripts = {
            "node": """#!/usr/bin/python3
import json,sys
from pathlib import Path
if sys.argv[1:] == ['--version']:print('vVERSION');raise SystemExit(0)
prefix=Path(sys.argv[sys.argv.index('--prefix')+1]);target=prefix/'node_modules/vite-plus';target.mkdir(parents=True,exist_ok=True)
v=json.loads((prefix/'package.json').read_text())['dependencies']['vite-plus'];(target/'package.json').write_text(json.dumps({'version':v}))
""".replace("VERSION", versions["node"]),
            "vp-cli": """#!/usr/bin/python3
import json,os,sys
from pathlib import Path
if sys.argv[1:] == ['--version']:print('VERSION');raise SystemExit(0)
Path(os.environ['VP_HOME']).joinpath('config.json').write_text(json.dumps({'defaultNodeVersion':sys.argv[-1]}))
""".replace("VERSION", versions["vp-cli"]),
            "pnpm": '#!/bin/sh\nprintf "%s\\n" "' + versions["pnpm"] + '"\n',
            "bun": '#!/bin/sh\nprintf "%s\\n" "' + versions["bun"] + '"\n',
        }
        pins = {}
        for name, body in scripts.items():
            path = self.artifacts / name
            member = {
                "node": "node-v" + versions["node"] + "-linux-x64/bin/node",
                "vp-cli": "vp",
                "pnpm": "package/pnpm",
                "bun": "bun-linux-x64/bun",
            }[name]
            if name == "bun":
                with zipfile.ZipFile(path, "w") as bundle:
                    bundle.writestr(member, body)
            else:
                with tarfile.open(path, "w:gz") as bundle:
                    item = tarfile.TarInfo(member)
                    item.mode = 0o755
                    item.size = len(body.encode())
                    bundle.addfile(item, io.BytesIO(body.encode()))
                    if name == "node":
                        item = tarfile.TarInfo(
                            "node-v"
                            + versions["node"]
                            + "-linux-x64/lib/node_modules/npm/bin/npm-cli.js"
                        )
                        item.size = 0
                        bundle.addfile(item, io.BytesIO(b""))
            pins[name] = {
                "version": versions[name],
                "url": "https://artifacts.test/" + name,
                "algorithm": "sha256",
                "digest": hashlib.sha256(path.read_bytes()).hexdigest(),
            }
        lock = json.dumps(
            {
                "name": "admitted-vp",
                "version": "1.4.0",
                "packages": {"": {"dependencies": {"vite-plus": "1.4.0"}}},
            }
        )
        pins["vp"] = {
            "version": "1.4.0",
            "url": "https://artifacts.test/vp",
            "algorithm": "sha256",
            "digest": "a" * 64,
            "lock_sha256": hashlib.sha256(lock.encode()).hexdigest(),
        }
        self.intent = {
            "schema_version": 1,
            "node_id": 73,
            "user": "orbit",
            "recipe_identity": "captured-recipe",
            "apt": {
                "snapshot": "20261029T000000Z",
                "packages": [
                    {"name": "curl", "version": "1.0", "artifact": None},
                    {"name": "composer", "version": "2.9.5-1", "artifact": None},
                ],
            },
            "javascript": {"user": "orbit", "tools": pins, "vp_lock": lock},
            "composer": {"user": "orbit", "version": "2.9.5-1"},
            "caddy": None,
            "browser": None,
        }
        for script in (bootstrap / "tool_scripts").glob("*.py"):
            source = (
                script.read_text()
                .replace(
                    'pathlib.Path("/opt/orbit")',
                    f"pathlib.Path({str(self.target / 'opt')!r})",
                )
                .replace(
                    'pathlib.Path("/usr/local/bin")',
                    f"pathlib.Path({str(self.target / 'bin')!r})",
                )
                .replace(
                    'pathlib.Path("/opt/orbit/composer")',
                    f"pathlib.Path({str(self.target / 'opt/composer')!r})",
                )
                .replace(
                    'pathlib.Path("/etc/os-release")',
                    f"pathlib.Path({str(self.target / 'os-release')!r})",
                )
            )
            source = (
                source.replace(
                    'pathlib.Path("/opt/orbit/chrome-headless-shell")',
                    f"pathlib.Path({str(self.target / 'chrome')!r})",
                )
                .replace(
                    'pathlib.Path("/etc/apparmor.d/orbit-chrome-headless-shell")',
                    f"pathlib.Path({str(self.target / 'apparmor/profile')!r})",
                )
                .replace(
                    'pathlib.Path("/usr/local/bin/chrome-headless-shell")',
                    f"pathlib.Path({str(self.target / 'bin/chrome-headless-shell')!r})",
                )
            )
            source = source.replace(
                'account = pwd.getpwnam(value["user"])',
                f'account = __import__("types").SimpleNamespace(pw_name="orbit",pw_uid=os.getuid(),pw_gid=os.getgid(),pw_dir={str(self.target / "home")!r})',
            )
            if "urllib.request" in source:
                source += "\n"
                source = source.replace(
                    '\nif __name__ == "__main__":',
                    "\nurllib.request.urlopen=lambda url: pathlib.Path("
                    + repr(str(self.artifacts))
                    + ' + "/" + url.rsplit("/",1)[1]).open("rb")\n\nif __name__ == "__main__":',
                )
            script.write_text(source)
        globals_ = {
            "name": "fixture-pinned-tools",
            "sshBinary": str(self.tools / "ssh"),
            "scpBinary": str(self.tools / "scp"),
            "transport": {
                "kind": "ssh",
                "user": "orbit",
                "identityFile": str(self.repo / "identity"),
            },
            "hosts": [
                {
                    "name": "target",
                    "address": "fixture",
                    "transport": {
                        "hostKeys": [
                            "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIEV4aXN0aW5nUGlubmVkSG9zdElkZW50aXR5UHVibGlj"
                        ]
                    },
                }
            ],
        }
        (self.repo / "identity").write_text("unused synthetic identity")
        program = (
            'import {roleToolSteps} from "./extensions/behaviors/operations/node-bootstrap/role_tool_steps.ts";console.log(JSON.stringify(roleToolSteps("tools","inputs.intent",'
            + json.dumps(globals_)
            + ")));"
        )
        output = subprocess.check_output(
            [os.environ.get("DENO_BIN", "/tmp/lane4-deno-2.9.8/deno"), "eval", program],
            cwd=ROOT,
            text=True,
        )
        steps = json.loads(output)
        for step in steps:
            self.assertEqual(step.pop("target"), "orbit-node-209")
            self.assertEqual(step.pop("labels"), {"node": "209"})
        steps.append(
            {
                "name": "verify-receipt",
                "dependsOn": [{"step": "tools", "condition": {"type": "succeeded"}}],
                "task": {
                    "type": "assert",
                    "expr": "steps.tools.outputs.receipt.ready && steps.tools.outputs.receipt.node_id == 73 && steps.tools.outputs.receipt.packages == inputs.expected_packages",
                    "message": "Exact tool contribution",
                    "severity": "high",
                },
            }
        )
        workflow = {
            "id": "45a0c995-9cdf-4272-934a-397c98a7f4b4",
            "name": "enrollment-tool-fixture",
            "inputs": {
                "type": "object",
                "properties": {
                    "intent": {"type": "object"},
                    "expected_packages": {"type": "array", "items": {"type": "string"}},
                },
            },
            "jobs": [{"name": "tools", "steps": steps}],
        }
        (self.repo / "workflows/workflow-tools.yaml").write_text(
            yaml.safe_dump(workflow, sort_keys=False).replace(
                "/tmp/orbit-node-tools-", str(self.target / "stage-")
            )
        )

    def run_tools(self, success=True):
        self.cli("workflow", "validate", "enrollment-tool-fixture")
        return json.loads(
            self.cli(
                "workflow",
                "run",
                "enrollment-tool-fixture",
                "--input",
                "intent=" + json.dumps(self.intent),
                "--input",
                "expected_packages="
                + json.dumps(
                    sorted(
                        [item["name"] for item in self.intent["apt"]["packages"]]
                        + (["chrome-headless-shell"] if self.intent["browser"] else [])
                    )
                ),
                success=success,
            ).stdout
        )

    def test_skipped_bootstrap_dependency_never_installs_tools(self):
        path = self.repo / "workflows/workflow-tools.yaml"
        workflow = yaml.safe_load(path.read_text())
        steps = workflow["jobs"][0]["steps"]
        steps[0]["dependsOn"] = [
            {"step": "bootstrap_verify", "condition": {"type": "succeeded"}}
        ]
        steps[:0] = [
            {
                "name": "bootstrap_platform",
                "task": {
                    "type": "assert",
                    "expr": "false",
                    "message": "fixture refusal",
                    "severity": "high",
                },
            },
            {
                "name": "bootstrap_verify",
                "dependsOn": [
                    {"step": "bootstrap_platform", "condition": {"type": "succeeded"}}
                ],
                "task": {
                    "type": "assert",
                    "expr": "true",
                    "message": "never",
                    "severity": "high",
                },
            },
        ]
        path.write_text(yaml.safe_dump(workflow, sort_keys=False))
        result = self.run_tools(False)
        states = {step["name"]: step["status"] for step in result["jobs"][0]["steps"]}
        self.assertEqual("skipped", states["tools_assets"])
        self.assertEqual("skipped", states["tools_cleanup"])
        self.assertFalse((self.target / "apt-installs").exists())

    def test_copy_failure_keeps_staging_and_resumes_same_root(self):
        (self.repo / "copy-fault").touch()
        failed = self.run_tools(False)
        states = {step["name"]: step["status"] for step in failed["jobs"][0]["steps"]}
        self.assertEqual("failed", states["tools_copy"])
        self.assertEqual("skipped", states["tools_cleanup"])
        self.assertTrue(list(self.target.glob("stage-*")))
        resumed = json.loads(
            self.cli(
                "workflow",
                "resume",
                "enrollment-tool-fixture",
                "--run",
                failed["id"],
                "--from",
                "tools_copy",
            ).stdout
        )
        self.assertEqual(failed["id"], resumed["id"])
        self.assertEqual("succeeded", resumed["status"])

    def test_existing_account_vite_scope_and_managed_packages_remain_selected(self):
        scope = self.target / "home/.vite-plus"
        (scope / "bin").mkdir(parents=True)
        (scope / "bin/vp").write_text("old-vp")
        packages = scope / "package_manager/global/package.json"
        packages.parent.mkdir(parents=True)
        packages.write_text('{"dependencies":{"retained-tool":"1.0"}}')
        self.assertEqual("succeeded", self.run_tools()["status"])
        self.assertFalse((self.target / "opt/vite-plus").exists())
        self.assertIn(str(scope), (self.target / "bin/vp").read_text())
        self.assertEqual(
            '{"dependencies":{"retained-tool":"1.0"}}', packages.read_text()
        )

    def test_application_tools_verify_pins_and_prepare_only_the_managed_scopes(self):
        result = self.run_tools()
        self.assertEqual("succeeded", result["status"])
        steps = {step["name"]: step for step in result["jobs"][0]["steps"]}
        self.assertEqual("succeeded", steps["verify-receipt"]["status"])
        self.assertTrue((self.target / "home/.local/share/vite-plus/bin/vp").is_file())
        self.assertEqual(
            {"require": {}},
            json.loads((self.target / "opt/composer/composer.json").read_text()),
        )
        self.assertEqual("succeeded", self.run_tools()["status"])

    def test_infrastructure_skips_application_installers(self):
        self.intent.update(javascript=None, composer=None)
        result = self.run_tools()
        steps = {step["name"]: step for step in result["jobs"][0]["steps"]}
        self.assertEqual("skipped", steps["tools_javascript"]["status"])
        self.assertEqual("skipped", steps["tools_composer"]["status"])
        self.assertFalse((self.target / "opt").exists())

    def test_package_failure_stops_later_mutation_and_readiness(self):
        (self.target / "fail-apt").touch()
        result = self.run_tools(False)
        self.assertEqual("failed", result["status"])
        self.assertFalse((self.target / "opt").exists())
        self.assertNotIn(
            "tools",
            [
                s["name"]
                for s in result["jobs"][0]["steps"]
                if s["status"] == "succeeded"
            ],
        )

    def test_composer_failure_resumes_same_root_after_verified_javascript_without_reinstalling_apt(
        self,
    ):
        (self.target / "fail-composer").touch()
        failed = self.run_tools(False)
        states = {step["name"]: step["status"] for step in failed["jobs"][0]["steps"]}
        self.assertEqual("succeeded", states["tools_javascript"])
        self.assertEqual("failed", states["tools_composer"])
        before = (self.target / "apt-installs").read_text()
        resumed = json.loads(
            self.cli(
                "workflow",
                "resume",
                "enrollment-tool-fixture",
                "--run",
                failed["id"],
                "--from",
                "tools_composer",
            ).stdout
        )
        self.assertEqual("succeeded", resumed["status"])
        self.assertEqual(failed["id"], resumed["id"])
        self.assertEqual(before, (self.target / "apt-installs").read_text())

    def test_production_browser_uses_its_pin_and_canonical_user_namespace_policy(self):
        self.intent.update(javascript=None, composer=None)
        archive = self.artifacts / "browser"
        with zipfile.ZipFile(archive, "w") as bundle:
            bundle.writestr(
                "chrome-headless-shell-linux64/chrome-headless-shell",
                '#!/bin/sh\nif [ "$1" = "--version" ]; then echo "Chrome Headless Shell 145.0.0.0"; else exec "$(dirname "$0")/chrome_crashpad_handler"; fi\n',
            )
            helper = zipfile.ZipInfo(
                "chrome-headless-shell-linux64/chrome_crashpad_handler"
            )
            helper.create_system = 3
            helper.external_attr = 0o100755 << 16
            bundle.writestr(helper, "#!/bin/sh\necho helper-ran\n")
        self.intent["browser"] = {
            "version": "145.0.0.0",
            "url": "https://artifacts.test/browser",
            "algorithm": "sha256",
            "digest": hashlib.sha256(archive.read_bytes()).hexdigest(),
        }
        self.assertEqual("succeeded", self.run_tools()["status"])
        policy = (self.target / "apparmor/profile").read_text()
        self.assertIn("145.0.0.0", policy)
        self.assertIn("userns,", policy)
        self.assertEqual(
            "helper-ran",
            subprocess.check_output(
                [str(self.target / "bin/chrome-headless-shell")], text=True
            ).strip(),
        )

    def test_bad_javascript_artifact_prevents_later_mutation_and_readiness(self):
        self.intent["javascript"]["tools"]["bun"]["digest"] = "f" * 64
        result = self.run_tools(False)
        self.assertEqual("failed", result["status"])
        self.assertFalse((self.target / "opt/composer").exists())
        self.assertNotIn(
            "tools",
            [
                s["name"]
                for s in result["jobs"][0]["steps"]
                if s["status"] == "succeeded"
            ],
        )


if __name__ == "__main__":
    unittest.main()
