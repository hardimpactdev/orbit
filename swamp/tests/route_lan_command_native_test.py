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


class RouteLanCommandNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.connection = install_ssh(self)
        target = self.repo / ".swamp/pulled-extensions/@swamp/cfgmgmt"
        shutil.copytree(ROOT / ".swamp/pulled-extensions/@swamp/cfgmgmt", target)
        inventory = json.loads(
            (ROOT / "extensions/models/upstream_extensions.json").read_text()
        )
        (self.repo / "extensions/models/upstream_extensions.json").write_text(
            json.dumps(
                {key: inventory[key] for key in ["@swamp/ssh", "@swamp/cfgmgmt"]}
            )
        )
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
        (tools / "ssh").write_text(
            "#!"
            + sys.executable
            + "\n"
            + """import shlex,subprocess,sys
args=sys.argv[1:]
if '-M' in args or '-O' in args or '-N' in args:raise SystemExit(0)
argv=shlex.split(args[-1])
if argv[:1]==['sudo']:
    argv=argv[argv.index('--')+1:]
result=subprocess.run(argv,input=sys.stdin.buffer.read(),capture_output=True)
sys.stdout.buffer.write(result.stdout);sys.stderr.buffer.write(result.stderr)
raise SystemExit(result.returncode)
"""
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
    row={'port':int(args[args.index('port')+1]),'protocol':args[args.index('proto')+1],'source':args[args.index('from')+1]}
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
        script = (
            self.repo
            / "extensions/behaviors/operations/routes/mutation-command/scripts/route_lan.py"
        )
        script.write_text(
            script.read_text()
            .replace('"/usr/sbin/ufw"', repr(str(fake)))
            .replace(
                '"/run/lock/orbit-firewall.lock"', repr(str(self.repo / "ufw.lock"))
            )
        )

        def paths(value):
            if isinstance(value, str):
                return value.replace("/tmp/orbit-route-", str(self.repo / "remote-"))
            if isinstance(value, list):
                return [paths(v) for v in value]
            if isinstance(value, dict):
                return {k: paths(v) for k, v in value.items()}
            return value

        for name in [
            "operation-route-lan-remove",
            "route-lan-apply",
            "route-lan-migrate",
        ]:
            workflow = paths(
                yaml.safe_load((ROOT / f"workflows/workflow-{name}.yaml").read_text())
            )
            for job in workflow["jobs"]:
                for step in job["steps"]:
                    self.assertEqual(step.pop("target"), "orbit-node-209")
                    self.assertEqual(step.pop("labels"), {"node": "209"})
            (self.repo / f"workflows/workflow-{name}.yaml").write_text(
                yaml.safe_dump(workflow, sort_keys=False)
            )
        self.tuple = {"port": 443, "protocol": "tcp", "source": "192.168.6.2"}

    def run_lan(
        self,
        ensure="present",
        migrate=True,
        success=True,
        rules=None,
        legacy_router=None,
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
                "migrate": migrate,
                "control_node_id": 209,
                "connection": self.connection,
                "host_keys": [PIN],
            },
        }
        workflow = "operation-route-lan-remove"
        if legacy_router is not None:
            workflow = "route-lan-apply"
            old = value["intent"]
            value["intent"] = {
                "control_node_id": 209,
                "connection": self.connection,
                "host_keys": [PIN],
                "rule": {
                    "node_id": 9,
                    "rules": old["rules"],
                    "migrate": migrate,
                    **({"legacy_router": True} if legacy_router else {}),
                },
            }
        result = json.loads(
            self.cli(
                "workflow",
                "run",
                workflow,
                "--input",
                json.dumps(value),
                success=success,
            ).stdout
        )
        return (
            result,
            json.loads(self.state.read_text()),
            [json.loads(line) for line in self.audit.read_text().splitlines()],
        )

    def initialize(self, tuples=None, legacy=None, **extra):
        self.state.write_text(
            json.dumps({"tuples": tuples or [], "legacy": legacy or [], **extra})
        )

    def test_present_before_legacy_removal_and_shared_tuple_survives(self):
        self.initialize(
            legacy=[
                "192.168.6.9 443/tcp ALLOW IN 192.168.6.2 # orbit:route-47-lan",
                "192.168.6.9 443/tcp ALLOW IN 192.168.6.2 # orbit:route-48-lan",
                "192.168.6.9 443/tcp ALLOW IN 192.168.6.8 # operator-owned",
            ]
        )
        result, state, calls = self.run_lan()
        self.assertEqual(state["tuples"], [self.tuple])
        self.assertEqual(len(state["legacy"]), 1)
        self.assertLess(
            next(i for i, c in enumerate(calls) if c[:1] == ["allow"]),
            next(i for i, c in enumerate(calls) if c[:2] == ["--force", "delete"]),
        )
        history = json.loads(
            self.cli("workflow", "history", "get", result["id"]).stdout
        )
        terminal = history["jobs"][0]["steps"][-1]
        self.assertEqual(
            json.loads(terminal["outputs"]["stdout"]),
            {"ready": True, "migrated_node_ids": [9]},
        )
        self.assertEqual(list(self.repo.glob("remote-*")), [])

    def test_last_route_withdraws_the_official_tuple(self):
        self.initialize(tuples=[self.tuple])
        _, state, calls = self.run_lan(ensure="absent", migrate=False)
        self.assertEqual(state["tuples"], [])
        self.assertTrue(any(c[:2] == ["delete", "allow"] for c in calls))
        self.assertFalse(any("numbered" in c for c in calls))

    def test_already_migrated_rerun_is_compliant_and_skips_custom_cleanup(self):
        self.initialize(tuples=[self.tuple])
        _, state, calls = self.run_lan(migrate=False)
        self.assertEqual(state["tuples"], [self.tuple])
        self.assertTrue(all(c == ["status"] for c in calls))

    def test_official_failure_keeps_old_rules_and_skips_cleanup(self):
        legacy = ["192.168.6.9 443/tcp ALLOW IN 192.168.6.2 # orbit:route-47-lan"]
        self.initialize(legacy=legacy, fail_apply=True)
        _, state, calls = self.run_lan(success=False)
        self.assertEqual(state["legacy"], legacy)
        self.assertFalse(any("numbered" in c for c in calls))

    def test_successful_no_effect_add_keeps_old_rules(self):
        legacy = ["192.168.6.9 443/tcp ALLOW IN 192.168.6.2 # orbit:route-47-lan"]
        self.initialize(legacy=legacy, ignore_apply=True)
        result, state, calls = self.run_lan(success=False)
        self.assertEqual(result["status"], "failed")
        self.assertEqual(state["tuples"], [])
        self.assertEqual(state["legacy"], legacy)
        self.assertFalse(any("numbered" in c for c in calls))

    def test_failed_migration_cannot_emit_success(self):
        self.initialize(
            legacy=["192.168.6.9 443/tcp ALLOW IN 192.168.6.2 # orbit:route-47-lan"],
            ignore_delete=True,
        )
        result, state, _ = self.run_lan(success=False)
        self.assertEqual(result["status"], "failed")
        self.assertEqual(state["tuples"], [self.tuple])

    def test_multiple_tuples_use_distinct_native_items(self):
        self.initialize()
        second = {**self.tuple, "source": "192.168.6.3"}
        rules = [
            {**self.tuple, "ensure": "present", "model_name": "r1"},
            {**second, "ensure": "present", "model_name": "r2"},
        ]
        _, state, _ = self.run_lan(migrate=False, rules=rules)
        self.assertCountEqual(state["tuples"], [self.tuple, second])

    def test_empty_desired_set_still_migrates_old_rules(self):
        self.initialize(
            legacy=["192.168.6.9 443/tcp ALLOW IN 192.168.6.2 # orbit:route-47-lan"]
        )
        _, state, _ = self.run_lan(rules=[])
        self.assertEqual(state["legacy"], [])
        self.assertEqual(state["tuples"], [])

    def test_router_cleanup_requires_explicit_flag_and_keeps_wireguard_and_foreign_tags(
        self,
    ):
        tags = [
            "192.168.6.9 443/tcp ALLOW IN 192.168.6.2 # orbit:router-lan-https:2",
            "192.168.6.9 53/tcp ALLOW IN 192.168.6.2 # orbit:router-lan-dns-tcp",
            "192.168.6.9 53/udp ALLOW IN 192.168.6.2 # orbit:router-lan-dns-udp:2",
            "10.44.0.9 ALLOW IN Anywhere on orbit # orbit:wireguard-members",
            "192.168.6.9 443/tcp ALLOW IN 192.168.6.2 # orbit:router-lan-https:operator",
        ]
        self.initialize(legacy=tags)
        _, state, _ = self.run_lan(legacy_router=False)
        self.assertEqual(state["legacy"], tags)
        result, state, calls = self.run_lan(legacy_router=True)
        self.assertEqual(result["status"], "succeeded")
        self.assertEqual(state["legacy"], tags[3:])
        self.assertEqual(state["tuples"], [self.tuple])
        self.assertTrue(any(c[:2] == ["--force", "delete"] for c in calls))

    def test_router_no_effect_official_add_cannot_remove_legacy_rules(self):
        legacy = ["192.168.6.9 443/tcp ALLOW IN 192.168.6.2 # orbit:router-lan-https:2"]
        self.initialize(legacy=legacy, ignore_apply=True)
        result, state, calls = self.run_lan(legacy_router=True, success=False)
        self.assertEqual(result["status"], "failed")
        self.assertEqual(state["legacy"], legacy)
        self.assertFalse(any("numbered" in c for c in calls))

    def test_router_migration_preserves_every_shared_desired_tuple(self):
        legacy = ["192.168.6.9 443/tcp ALLOW IN 192.168.6.2 # orbit:router-lan-https:2"]
        dns_tcp = {**self.tuple, "port": 53}
        dns_udp = {**dns_tcp, "protocol": "udp"}
        self.initialize(legacy=legacy)
        desired = [self.tuple, dns_tcp, dns_udp]
        rules = [
            {**row, "ensure": "present", "model_name": f"r{index}"}
            for index, row in enumerate(desired)
        ]
        _, state, calls = self.run_lan(legacy_router=True, rules=rules)
        self.assertEqual(state["legacy"], [])
        self.assertCountEqual(state["tuples"], desired)
        first_delete = next(
            i for i, c in enumerate(calls) if c[:2] == ["--force", "delete"]
        )
        self.assertEqual(sum(c[:1] == ["allow"] for c in calls[:first_delete]), 3)
