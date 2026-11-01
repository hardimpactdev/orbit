"""Failure cases that must never count as a successful Router cutover."""

import copy
import importlib.util
import json
import os
import runpy
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

spec = importlib.util.spec_from_file_location(
    "router_proof", Path(__file__).with_name("support.py")
)
proof = importlib.util.module_from_spec(spec)
spec.loader.exec_module(proof)


class RouterProof(unittest.TestCase):
    def setUp(self):
        self.context = {
            "cluster_id": 41,
            "route_id": 45,
            "old": {"id": 43, "wireguard_ip": "192.0.2.43", "lan_ip": None},
            "new": {"id": 44, "wireguard_ip": "192.0.2.44", "lan_ip": None},
        }
        self.native = {
            "ready": True,
            "selected": True,
            "restored": False,
            "cleanup_complete": True,
        }
        self.operation = {
            "id": 51,
            "kind": "cluster.router_set",
            "target_type": "cluster_router",
            "target_id": 41,
            "status": "succeeded",
            "error_code": None,
            "cancel_requested": False,
            "result": self.native.copy(),
            "input": {
                "node_id": 44,
                "old_node_id": 43,
                "cluster_router": {
                    "candidate": {"node_id": 44},
                    "noop": False,
                    "route_ids": [45],
                },
            },
        }

    def test_forward_needs_successful_selection_not_restoration(self):
        proof.check_terminal(self.operation, self.native, self.context)
        for field in ("ready", "selected", "cleanup_complete", "restored"):
            with self.subTest(field=field), self.assertRaises(AssertionError):
                native = {**self.native, field: not self.native[field]}
                proof.check_terminal(
                    {**self.operation, "result": native}, native, self.context
                )

    def test_wrong_projection_candidate_or_route_refuses(self):
        for path, value in [
            ("status", "failed"),
            ("result", {}),
            ("cancel_requested", True),
            ("target_id", 99),
        ]:
            with self.subTest(path=path), self.assertRaises(AssertionError):
                proof.check_terminal(
                    {**self.operation, path: value}, self.native, self.context
                )
        for field, value in [("node_id", 43), ("old_node_id", 44)]:
            operation = copy.deepcopy(self.operation)
            operation["input"][field] = value
            with self.subTest(field=field), self.assertRaises(AssertionError):
                proof.check_terminal(operation, self.native, self.context)
        operation = copy.deepcopy(self.operation)
        operation["input"]["cluster_router"]["route_ids"] = []
        with self.assertRaises(AssertionError):
            proof.check_terminal(operation, self.native, self.context)

    def test_dns_loaded_state_and_actual_https_are_required(self):
        node = {"wireguard_ip": "192.0.2.44", "lan_ip": None}
        view = {
            "dns": ["192.0.2.44"],
            "hosts": ["proof.test"],
            "active": True,
            "loaded_matches_disk": True,
            "https": "router-proof-ok\n",
        }
        proof.assert_selected(view, "proof.test", node, True)
        for field, value in [
            ("dns", []),
            ("dns", ["192.0.2.43"]),
            ("hosts", []),
            ("active", False),
            ("loaded_matches_disk", False),
            ("https", "unrelated site"),
        ]:
            with self.subTest(field=field), self.assertRaises(AssertionError):
                proof.assert_selected({**view, field: value}, "proof.test", node, True)
        with self.assertRaises(AssertionError):
            proof.assert_selected(view, "proof.test", node, False)

    def test_actual_parity_checks_prepared_leaves_not_empty_forward_certificates(self):
        kind = Path(__file__).parent
        context = {
            **self.context,
            "old_role": "spare",
            "new_role": "spare-2",
            "gateway": {"id": 40},
            "app": {"id": 42},
            "domain": "proof.test",
        }
        prepared = [
            {
                "node_id": 44,
                "scope": "route-45-router",
                "root_hash": "admitted-ca",
                "user": "orbit",
                "domain": "proof.test",
            }
        ]
        operation = copy.deepcopy(self.operation)
        operation["input"]["route_mutations"] = {
            "forward": {
                "certificates": [],
                "prepare_certificates": prepared,
                "cleanup": [],
                "publications": [
                    {
                        "node_id": 43,
                        "after": {"caddy": {"content": "old"}, "dns": None},
                    },
                    {
                        "node_id": 44,
                        "after": {"caddy": {"content": "new"}, "dns": None},
                    },
                ],
            }
        }
        with tempfile.TemporaryDirectory() as tmp:
            baseline = Path(tmp) / "baseline.json"
            outputs = Path(tmp) / "outputs.json"
            baseline.write_text(json.dumps(context))
            outputs.write_text(json.dumps({"stdout": json.dumps(self.native)}))
            for root_hash, empty in [
                ("admitted-ca", False),
                ("wrong-ca", False),
                ("admitted-ca", True),
            ]:
                seen = []

                def read_node(role, domain, leaves, **kwargs):
                    seen.extend(leaves)
                    observed = (
                        []
                        if empty
                        else [
                            {
                                "scope": leaf["scope"],
                                "root_hash": root_hash,
                                "verified": True,
                            }
                            for leaf in leaves
                        ]
                    )
                    return {
                        "disk_sha256": proof.digest(
                            "old" if role == "spare" else "new"
                        ),
                        "active": True,
                        "loaded_matches_disk": True,
                        "leaves": observed,
                        "cleanup": [],
                    }

                api = {
                    "baseline_path": lambda _: baseline,
                    "orbit": lambda command, _: (
                        operation
                        if command == "operation:show"
                        else {"router": {"id": 44}}
                    ),
                    "check_terminal": proof.check_terminal,
                    "read_node": read_node,
                    "assert_selected": lambda *a, **kw: None,
                    "digest": proof.digest,
                }
                original = runpy.run_path
                with (
                    self.subTest(root_hash=root_hash, empty=empty),
                    patch.dict(
                        os.environ,
                        {
                            "PROOF_KIND_DIR": str(kind),
                            "PROOF_OUTPUTS": str(outputs),
                            "PROOF_OPERATION_ID": "51",
                        },
                    ),
                    patch("sys.argv", ["parity.py", "01-forward"]),
                    patch.object(runpy, "run_path", return_value=api),
                ):
                    if root_hash == "admitted-ca" and not empty:
                        original(str(kind / "parity.py"))
                        self.assertEqual(prepared, seen)
                    else:
                        with self.assertRaises((AssertionError, ValueError)):
                            original(str(kind / "parity.py"))


if __name__ == "__main__":
    unittest.main()
