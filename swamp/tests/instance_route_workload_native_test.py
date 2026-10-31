"""Native workload branches with official SSH/file types at a disposable SSH binary."""

import json
import shutil
import sys
from pathlib import Path

import yaml

from tests.native_harness import NativeTestCase
from tests.route_ssh_fixture import PIN, install_ssh

ROOT = Path(__file__).resolve().parents[1]


class InstanceRouteWorkloadNative(NativeTestCase):
    native_timeout = 120

    def setUp(self):
        super().setUp()
        connection = install_ssh(self)
        self.copy_behaviors("operations/instance-route-workload")
        target = self.repo / ".swamp/pulled-extensions/@swamp/cfgmgmt"
        shutil.copytree(ROOT / ".swamp/pulled-extensions/@swamp/cfgmgmt", target)
        inventory = json.loads(
            (ROOT / "extensions/models/upstream_extensions.json").read_text()
        )
        (self.repo / "extensions/models/upstream_extensions.json").write_text(
            json.dumps(
                {key: inventory[key] for key in ("@swamp/ssh", "@swamp/cfgmgmt")}
            )
        )
        self.audit = self.repo / "audit"
        self.native_env["FIXTURE_WORKLOAD_AUDIT"] = str(self.audit)
        tools = self.repo / "ssh-tools"
        ssh = tools / "ssh"
        ssh.write_text(
            "#!"
            + sys.executable
            + "\n"
            + """import ast,json,os,shlex,subprocess,sys
from pathlib import Path
args=sys.argv[1:]
if '-M' in args or '-O' in args or '-N' in args:raise SystemExit(0)
body=sys.stdin.buffer.read()
remote=args[-1]
def audit(action):
    with Path(os.environ['FIXTURE_WORKLOAD_AUDIT']).open('a') as stream:stream.write(action+'\\n')
if b'WORKLOAD_INPUT = json.loads(' in body:
    tree=ast.parse(body.decode())
    data=next(json.loads(ast.literal_eval(node.value.args[0])) for node in tree.body if isinstance(node,ast.Assign) and isinstance(node.targets[0],ast.Name) and node.targets[0].id=='WORKLOAD_INPUT')
    if 'home' in data:
        audit('served')
        print(json.dumps({'ready':True,'user':data['user'],'sites':[]}))
    else:
        audit('source')
        print('{"ready":true}')
    raise SystemExit(0)
if '/usr/bin/cmp' in remote:raise SystemExit(0)
if 'php-fpm8.5' in remote:
    audit('validate');raise SystemExit(int(os.environ.get('FIXTURE_INVALID_POOL','0')))
if '/usr/bin/systemctl' in remote:audit('reload');raise SystemExit(0)
if '/etc/' in remote:raise SystemExit('Refusing real /etc in workload fixture')
argv=shlex.split(remote)
if argv[:1]==['sudo']:argv=argv[argv.index('--')+1:]
result=subprocess.run(argv,input=body,capture_output=True)
sys.stdout.buffer.write(result.stdout);sys.stderr.buffer.write(result.stderr)
raise SystemExit(result.returncode)
"""
        )
        ssh.chmod(0o700)
        self.pool = self.repo / "runtime/generated/pool.conf"
        self.pool.parent.mkdir(parents=True)
        self.pool.write_text("old-pool")
        raw = (ROOT / "workflows/workflow-instance-route-workload.yaml").read_text()
        workflow = yaml.safe_load(raw)
        for step in workflow["jobs"][0]["steps"]:
            self.assertEqual(step.pop("target"), "orbit-node-209")
            self.assertEqual(step.pop("labels"), {"node": "209"})
            if step["name"] == "runtime-pool":
                step["task"]["globalArgs"]["path"] = str(self.pool)
                step["task"]["globalArgs"].pop("owner")
                step["task"]["globalArgs"].pop("group")
        (self.repo / "workflows/workflow-instance-route-workload.yaml").write_text(
            yaml.safe_dump(workflow, sort_keys=False)
        )
        self.input = {
            "operation_id": 7,
            "node_id": 9,
            "kind": "route.update",
            "execution_secrets": {},
            "intent": {
                "node_id": 9,
                "instance_id": 5,
                "connection": connection,
                "host_keys": [PIN],
                "plan": {"source_access": None, "served_roots": None, "runtime": None},
            },
        }

    def production(self):
        import hashlib

        self.input["intent"]["plan"].update(
            served_roots={
                "user": "orbit-app-5",
                "home": "/home/orbit-app-5",
                "default_web_root": "public",
                "default_directory": "",
                "served": [{"web_root": "docs/public", "directory": "docs"}],
            },
            runtime={
                "user": "orbit-app-5",
                "version": "8.5",
                "marker": "public-marker\n",
                "pool": "candidate-pool",
                "pool_sha256": hashlib.sha256(b"candidate-pool").hexdigest(),
            },
        )

    def run_workload(self, success=True):
        self.cli("workflow", "validate", "instance-route-workload")
        reply = json.loads(
            self.cli(
                "workflow",
                "run",
                "instance-route-workload",
                "--input",
                json.dumps(self.input),
                success=success,
            ).stdout
        )
        return json.loads(self.cli("workflow", "history", "get", reply["id"]).stdout)

    def test_dev_branch_does_not_read_empty_production_outputs(self):
        self.input["intent"]["plan"]["source_access"] = {"user": "orbit", "sites": []}
        self.run_workload()
        self.assertEqual(self.audit.read_text().splitlines(), ["source"])
        self.assertEqual(self.pool.read_text(), "old-pool")

    def test_production_uses_the_official_file_before_validate_reload(self):
        self.production()
        self.run_workload()
        self.assertEqual(self.pool.read_text(), "candidate-pool")
        self.assertEqual(
            self.audit.read_text().splitlines(),
            ["served", "source", "validate", "reload"],
        )

    def test_invalid_pool_blocks_reload_and_completion(self):
        self.production()
        self.native_env["FIXTURE_INVALID_POOL"] = "1"
        history = self.run_workload(success=False)
        self.assertEqual(history["status"], "failed")
        self.assertNotIn("reload", self.audit.read_text().splitlines())
