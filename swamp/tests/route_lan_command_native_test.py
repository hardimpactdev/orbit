"""Official firewall tuple convergence precedes the approved one-time legacy cleanup."""

import json
import shutil
import sys
from pathlib import Path

import yaml

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from tests.native_harness import NativeTestCase
from tests.route_ssh_fixture import PIN, install_ssh

ROOT = Path(__file__).resolve().parents[1]


class RouteLanFixture(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.connection = install_ssh(self)
        self.copy_behaviors("operations/routes/mutation-command")
        path = (
            self.repo
            / "extensions/behaviors/operations/routes/publication-command/sections.ts"
        )
        path.parent.mkdir(parents=True)
        shutil.copy(
            ROOT
            / "extensions/behaviors/operations/routes/publication-command/sections.ts",
            path,
        )
        self.state = self.repo / "ufw-state.json"
        self.audit = self.repo / "ufw-audit.jsonl"
        tools = self.repo / "ssh-tools"
        ssh = tools / "ssh"
        ssh.write_text(
            ssh.read_text().replace(
                "args=sys.argv[1:]",
                "args=sys.argv[1:]\n"
                "if '-O' not in args and '-M' not in args:\n"
                "    known=next((v.split('=',1)[1] for v in args if v.startswith('UserKnownHostsFile=')), '')\n"
                "    if 'StrictHostKeyChecking=yes' not in args or not known or "
                + repr(PIN)
                + " not in Path(known).read_text():raise SystemExit('admitted pin required')",
            )
        )
        fake = tools / "ufw"
        fake.write_text(
            "#!"
            + sys.executable
            + "\n"
            + """import fcntl,json,os,sys
from pathlib import Path
path=Path(os.environ['FIXTURE_UFW_STATE'])
# The executable boundary serializes transactions, as the real UFW tool does.
lock=path.with_suffix('.lock').open('a');fcntl.flock(lock,fcntl.LOCK_EX)
state=json.loads(path.read_text());args=sys.argv[1:]
with Path(os.environ['FIXTURE_UFW_AUDIT']).open('a') as log:log.write(json.dumps(args)+'\\n')
if args[:1]==['status']:
    print('Status: '+state.get('backend','active'))
    rows=[f"{r['port']}/{r['protocol']} ALLOW IN {r['source']}" for r in state['tuples']]+state['legacy']
    for index,row in enumerate(rows,1):print((f'[{index}] ' if 'numbered' in args else '')+row)
elif args[:2]==['--force','delete']:
    if not state.get('ignore_delete'):del state['legacy'][int(args[2])-len(state['tuples'])-1]
    path.write_text(json.dumps(state))
elif args[:1]==['allow'] or args[:2]==['delete','allow']:
    if state.get('fail_apply'):raise SystemExit(1)
    if state.get('ignore_apply'):raise SystemExit(0)
    row={'port':int(args[args.index('port')+1]),'protocol':args[args.index('proto')+1],'source':args[args.index('from')+1].replace('any','Anywhere')}
    if args[0]=='delete':state['tuples']=[r for r in state['tuples'] if r!=row]
    elif row not in state['tuples']:state['tuples'].append(row)
    path.write_text(json.dumps(state))
else:raise SystemExit(9)
"""
        )
        fake.chmod(0o700)
        self.native_env.update(
            FIXTURE_UFW_STATE=str(self.state), FIXTURE_UFW_AUDIT=str(self.audit)
        )
        def paths(value):
            if isinstance(value, str):
                return value.replace(
                    "/tmp/orbit-route-", str(self.repo / "remote-")
                ).replace("/usr/sbin/ufw", str(fake))
            if isinstance(value, list):
                return [paths(v) for v in value]
            if isinstance(value, dict):
                return {k: paths(v) for k, v in value.items()}
            return value

        for name in [
            "operation-route-lan-remove",
            "route-lan-apply",
        ]:
            workflow = paths(
                yaml.safe_load((ROOT / f"workflows/workflow-{name}.yaml").read_text())
            )
            for job in workflow["jobs"]:
                for step in job["steps"]:
                    self.assertEqual(step.pop("labels"), {"role": "swamp"})
                    self.assertNotIn("labels", step)
            (self.repo / f"workflows/workflow-{name}.yaml").write_text(
                yaml.safe_dump(workflow, sort_keys=False)
            )
        self.tuple = {"port": 443, "protocol": "tcp", "source": "192.168.6.2"}

    def run_lan(
        self,
        ensure="present",
        success=True,
        rules=None,
        apply_parent=False,
        host_keys=None,
        input_refused=False,
    ):
        value = {
            "operation_id": 1,
            "node_id": 9,
            "kind": "route.lan_remove",
            "execution_secrets": {},
            "intent": {
                "route_id": 47,
                "node_id": 9,
                "rules": rules
                if rules is not None
                else [{**self.tuple, "ensure": ensure, "model_name": "r1"}],
                "control_node_id": 209,
                "connection": self.connection,
                "host_keys": [PIN] if host_keys is None else host_keys,
            },
        }
        workflow = "operation-route-lan-remove"
        if apply_parent:
            workflow = "route-lan-apply"
            old = value["intent"]
            value["intent"] = {
                "control_node_id": 209,
                "connection": self.connection,
                "host_keys": [PIN] if host_keys is None else host_keys,
                "rule": {
                    "node_id": 9,
                    "rules": old["rules"],
                },
            }
        process = self.cli(
            "workflow", "run", workflow, "--input", json.dumps(value), success=success
        )
        if input_refused:
            return process
        result = json.loads(process.stdout)
        return (
            result,
            json.loads(self.state.read_text()),
            [json.loads(line) for line in self.audit.read_text().splitlines()]
            if self.audit.exists()
            else [],
        )

    def initialize(self, tuples=None, legacy=None, **extra):
        self.state.write_text(
            json.dumps({"tuples": tuples or [], "legacy": legacy or [], **extra})
        )


class RouteLanCommandNative(RouteLanFixture):
    def test_a_tuple_is_added_and_other_rules_are_left_alone(self):
        legacy = ["192.168.6.9 443/tcp ALLOW IN 192.168.6.2 # orbit:route-48-lan"]
        self.initialize(legacy=legacy)
        result, state, calls = self.run_lan()
        self.assertEqual(state["tuples"], [self.tuple])
        self.assertEqual(state["legacy"], legacy)
        self.assertFalse(any(c[:2] == ["--force", "delete"] for c in calls))
        history = json.loads(
            self.cli("workflow", "history", "get", result["id"]).stdout
        )
        terminal = history["jobs"][0]["steps"][-1]
        self.assertEqual(json.loads(terminal["outputs"]["stdout"]), {"ready": True})

    def test_last_route_withdraws_the_official_tuple(self):
        self.initialize(tuples=[self.tuple])
        _, state, calls = self.run_lan(ensure="absent")
        self.assertEqual(state["tuples"], [])
        self.assertTrue(any(c[:2] == ["delete", "allow"] for c in calls))
        self.assertFalse(any("numbered" in c for c in calls))

    def test_rerun_is_compliant(self):
        self.initialize(tuples=[self.tuple])
        _, state, calls = self.run_lan()
        self.assertEqual(state["tuples"], [self.tuple])
        self.assertTrue(all(c == ["status"] for c in calls))

    def test_official_failure_fails_the_run(self):
        self.initialize(fail_apply=True)
        result, state, _ = self.run_lan(success=False)
        self.assertEqual(result["status"], "failed")
        self.assertEqual(state["tuples"], [])

    def test_a_no_effect_add_fails_the_run(self):
        self.initialize(ignore_apply=True)
        result, state, _ = self.run_lan(success=False)
        self.assertEqual(result["status"], "failed")
        self.assertEqual(state["tuples"], [])

    def test_multiple_tuples_use_distinct_native_items(self):
        self.initialize()
        second = {**self.tuple, "source": "192.168.6.3"}
        rules = [
            {**self.tuple, "ensure": "present", "model_name": "r1"},
            {**second, "ensure": "present", "model_name": "r2"},
        ]
        _, state, _ = self.run_lan(rules=rules)
        self.assertCountEqual(state["tuples"], [self.tuple, second])

    def test_the_apply_child_reports_ready(self):
        self.initialize()
        result, state, _ = self.run_lan(apply_parent=True)
        self.assertEqual(result["status"], "succeeded")
        self.assertEqual(state["tuples"], [self.tuple])

    def test_wrong_or_missing_admitted_pins_refuse_before_any_ufw_command(self):
        self.initialize()
        result, state, calls = self.run_lan(
            host_keys=[PIN[:-4] + "AgIC"], success=False
        )
        self.assertEqual(result["status"], "failed")
        self.assertEqual(state["tuples"], [])
        self.assertEqual(calls, [])
        process = self.run_lan(host_keys=[], success=False, input_refused=True)
        self.assertEqual(process.stdout, "")
        self.assertIn("host_keys", process.stderr)
        self.assertFalse(self.audit.exists())
