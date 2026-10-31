"""Native Router parent scheduling with isolated public child-effect boundaries."""

import json
import sys
import unittest
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / "tests"))
from native_harness import NativeTestCase


class ClusterNative(NativeTestCase):
    native_timeout = 90

    def setUp(self):
        super().setUp()
        self.copy_behaviors(
            "operations/cluster-router",
            "operations/routes/mutation-command",
            "operations/routes/publication-command",
            "operations/node-role-command",
            "operations/node-bootstrap",
        )
        names = set()
        source = None
        for name in [
            "operation-cluster-router",
            "cluster-router-forward",
            "cluster-router-restore",
        ]:
            workflow = yaml.safe_load(
                (ROOT / f"workflows/workflow-{name}.yaml").read_text()
            )
            if source is None:
                source = workflow
            for step in workflow["jobs"][0]["steps"]:
                step.pop("target", None)
                step.pop("labels", None)
                if step["task"]["type"] == "workflow":
                    names.add(step["task"]["workflowIdOrName"])
            (self.repo / f"workflows/workflow-{name}.yaml").write_text(
                yaml.safe_dump(workflow, sort_keys=False)
            )
        names -= {"cluster-router-forward", "cluster-router-restore"}
        command = """python3 - <<'PY'
import json,os,pathlib
p=pathlib.Path('native-cluster.json');s=json.loads(p.read_text());name=os.environ['ACTION'];name='probe' if name=='cluster-router-probe' else name
s['events'].append(name)
if name in ['route-certificate-publish','route-certificate-remove']:s.setdefault('signing_roots',[]).append(os.environ['ROOT_RUN'])
p.write_text(json.dumps(s))
if s.get('failures',[None])[0]==name:
 s['failures'].pop(0);p.write_text(json.dumps(s));raise SystemExit(1)
if s.get('fail')==name:
 s['fail']=None;p.write_text(json.dumps(s));raise SystemExit(1)
print('{"ready":true}')
PY"""
        for i, name in enumerate(sorted(names)):
            inputs = dict(source["inputs"])
            inputs["properties"] = dict(
                inputs["properties"],
                parent_run_id={"type": "string"},
                restoring={"type": "boolean"},
            )
            task = dict(
                type="behavior",
                behaviorType="command/shell",
                behaviorName="fixture-effect",
                methodName="execute",
                inputs=dict(
                    run=command,
                    env=dict(
                        ACTION=name,
                        ROOT_RUN='${{ has(inputs.parent_run_id) ? inputs.parent_run_id : "" }}',
                    ),
                ),
            )
            child = dict(
                id=f"00000000-0000-4000-8000-{i + 1:012d}",
                name=name,
                inputs=inputs,
                jobs=[dict(name="effect", steps=[dict(name="effect", task=task)])],
            )
            (self.repo / "workflows" / f"workflow-{name}.yaml").write_text(
                yaml.safe_dump(child, sort_keys=False)
            )
        connection = dict(
            nodeHost="192.0.2.9",
            nodeUser="orbit",
            nodePort=22,
            nodeIdentityFile="/fixture/key",
            become=True,
        )
        pub = dict(
            node_id=9,
            user="orbit",
            serves_routes=True,
            control_node_id=209,
            connection=connection,
            host_keys=["ssh-ed25519 fixture"],
            **{
                side: dict(caddy={}, php={}, dns=dict(digest=side))
                for side in ["before", "prepared", "withdrawn", "after"]
            },
        )
        leaf = dict(node_id=9, scope="route-7-router")
        stage = dict(
            routes=[],
            publications=[pub],
            certificates=[leaf],
            prepare_certificates=[leaf],
            url_writes=[],
            lan=[],
            retire_lan=[],
            cleanup=[leaf],
        )
        self.intent = dict(
            cluster_router=dict(
                noop=False,
                candidate=dict(probes=[]),
                old_nodes=[
                    dict(
                        firewall=dict(
                            node_id=9, rules=[], migrate=True, legacy_router=True
                        ),
                        connection=connection,
                        host_keys=["fixture"],
                    )
                ],
            ),
            route_mutations=dict(forward=stage, restore=stage),
        )
        self.state = dict(events=[])

    def run_parent(self, success=True):
        (self.repo / "native-cluster.json").write_text(json.dumps(self.state))
        self.cli("workflow", "validate", "operation-cluster-router")
        self.cli(
            "workflow",
            "run",
            "operation-cluster-router",
            "--input",
            "operation_id=17",
            "--input",
            "node_id=9",
            "--input",
            "kind=cluster.router_set",
            "--input",
            "intent=" + json.dumps(self.intent),
            "--input",
            "execution_secrets={}",
            success=success,
        )
        history = json.loads(
            self.cli("workflow", "history", "get", "operation-cluster-router").stdout
        )
        result = next(s for s in history["jobs"][0]["steps"] if s["name"] == "result")
        self.assertEqual("succeeded", result["status"])
        self.last_history = history
        self.last_state = json.loads((self.repo / "native-cluster.json").read_text())
        return json.loads(result["outputs"]["stdout"]), self.last_state["events"]

    def test_set_completes_route_cleanup_before_obsolete_baseline(self):
        result, events = self.run_parent()
        self.assertTrue(result["ready"])
        self.assertTrue(result["selected"])
        self.assertTrue(result["cleanup_complete"])
        self.assertEqual("route-lan-apply", events[-1])
        self.assertLess(
            events.index("route-dns-grace"), events.index("route-certificate-remove")
        )

    def test_signing_keeps_the_real_root_run_id_across_both_phase_children(self):
        self.state["fail"] = "route-dns-publish"
        result, _ = self.run_parent(False)
        self.assertTrue(result["restored"])
        self.assertTrue(self.last_state["signing_roots"])
        self.assertEqual(
            set(self.last_state["signing_roots"]), {self.last_history["id"]}
        )

    def test_failed_restore_retains_observed_selection_and_never_retires_old_router(
        self,
    ):
        self.state["failures"] = [
            "route-certificate-remove",
            "route-certificate-publish",
        ]
        result, events = self.run_parent(False)
        self.assertFalse(result["ready"])
        self.assertTrue(result["selected"])
        self.assertFalse(result["restored"])
        self.assertNotIn("route-lan-apply", events)

    def test_empty_optional_fanouts_still_prove_required_publication(self):
        for phase in self.intent["route_mutations"].values():
            phase["cleanup"] = []
            phase["prepare_certificates"] = []
            phase["certificates"] = []
        result, events = self.run_parent()
        self.assertTrue(result["ready"])
        self.assertIn("operation-route-caddy-delta", events)

    def test_unset_skips_candidate_preparation(self):
        self.intent["cluster_router"]["candidate"] = None
        result, events = self.run_parent()
        self.assertTrue(result["ready"])
        self.assertNotIn("cluster-router-prepare", events)

    def test_workload_probe_precedes_candidate_caddy_and_dns(self):
        self.intent["cluster_router"]["candidate"].update(
            connection=dict(
                nodeHost="fixture",
                nodeUser="orbit",
                nodePort=22,
                nodeIdentityFile="/fixture/key",
            ),
            host_keys=["fixture"],
            probes=[
                dict(
                    connection=dict(
                        nodeHost="fixture",
                        nodeUser="orbit",
                        nodePort=22,
                        nodeIdentityFile="/fixture/key",
                    ),
                    host_keys=["fixture"],
                    probe=dict(address="192.0.2.13", port=443, domain="app.test"),
                )
            ],
        )
        result, events = self.run_parent()
        self.assertTrue(result["ready"])
        self.assertLess(
            events.index("probe"), events.index("operation-route-caddy-delta")
        )
        self.assertLess(events.index("probe"), events.index("route-dns-publish"))

    def test_workload_probe_failure_suppresses_candidate_publication(self):
        self.intent["cluster_router"]["candidate"].update(
            connection=dict(
                nodeHost="fixture",
                nodeUser="orbit",
                nodePort=22,
                nodeIdentityFile="/fixture/key",
            ),
            host_keys=["fixture"],
            probes=[
                dict(
                    connection=dict(
                        nodeHost="fixture",
                        nodeUser="orbit",
                        nodePort=22,
                        nodeIdentityFile="/fixture/key",
                    ),
                    host_keys=["fixture"],
                    probe=dict(address="192.0.2.13", port=443, domain="app.test"),
                )
            ],
        )
        self.state["fail"] = "probe"
        result, events = self.run_parent(False)
        self.assertFalse(result["ready"])
        self.assertFalse(result["selected"])
        self.assertTrue(result["restored"])
        self.assertLess(
            events.index("probe"), events.index("operation-route-caddy-delta")
        )

    def test_noop_runs_no_effect(self):
        self.intent["cluster_router"].update(noop=True, old_nodes=[])
        result, events = self.run_parent()
        self.assertTrue(result["ready"])
        self.assertEqual([], events)

    def test_failed_dns_restores_before_terminal_failure(self):
        self.state["fail"] = "route-dns-publish"
        result, events = self.run_parent(False)
        self.assertFalse(result["ready"])
        self.assertTrue(result["restored"])
        self.assertFalse(result["selected"])
        self.assertNotIn("route-lan-apply", events)

    def test_cleanup_failure_retains_selected_candidate(self):
        self.state["fail"] = "route-lan-apply"
        result, _ = self.run_parent(False)
        self.assertFalse(result["ready"])
        self.assertTrue(result["selected"])
        self.assertFalse(result["cleanup_complete"])

    def test_preparation_failure_starts_no_publication(self):
        self.state["fail"] = "cluster-router-prepare"
        result, events = self.run_parent(False)
        self.assertFalse(result["ready"])
        self.assertEqual(["cluster-router-prepare"], events)


if __name__ == "__main__":
    unittest.main()
