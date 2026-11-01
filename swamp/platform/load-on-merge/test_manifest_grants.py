import copy
import hashlib
import json
import pathlib
import tempfile
import unittest

import manifest_grants as M

SHA = "a" * 40
UUID = "11111111-2222-3333-4444-555555555555"
POLICY = {
    "version": 2,
    "principal": M.PRINCIPAL,
    "workflows": [{"workflow": "operation-example", "actions": ["run", "read"]}],
    "vaults": [
        {
            "vault": "orbit-inputs",
            "key_pattern": "^example-[0-9]+$",
            "actions": ["write", "read"],
        }
    ],
}
RAW = json.dumps(POLICY).encode()


class Native:
    def __init__(self):
        self.grants = [
            {
                "id": "other",
                "subject": {"kind": "user", "name": "other"},
                "effect": "allow",
                "resource": {"kind": "workflow", "pattern": "*"},
                "actions": ["run"],
            }
        ]
        self.groups = [{"name": "existing", "members": ["user:other"]}]
        self.catalog = {"results": [{"name": "operation-example", "id": UUID}]}
        self.calls = []
        self.fail = None

    def __call__(self, args):
        self.calls.append(args)
        if args == ["access", "grant", "list"]:
            return copy.deepcopy(self.grants)
        if args == ["access", "group", "list"]:
            return copy.deepcopy(self.groups)
        if args == ["workflow", "search"]:
            return self.catalog
        if args[:3] == ["access", "grant", "create"]:
            kind, pattern = args[args.index("--on") + 1].split(":", 1)
            grant = {
                "id": str(len(self.grants)),
                "subject": M.SUBJECT,
                "effect": "allow",
                "resource": {"kind": kind, "pattern": pattern},
                "actions": args[args.index("--allow") + 1].split(","),
            }
            if "--when" in args:
                grant["condition"] = args[args.index("--when") + 1]
            self.grants.append(grant)
            if self.fail == "create":
                raise TimeoutError("accepted_but_reply_lost")
            return {"status": "succeeded"}
        if args == ["access", "reload"]:
            if self.fail == "reload":
                raise TimeoutError("reload_reply_lost")
            return {"success": True}
        raise AssertionError(args)


class Reconciliation(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.directory = pathlib.Path(self.tmp.name)
        self.native = Native()

    def apply(self):
        return M.apply(RAW, SHA, self.directory, self.native)

    def test_partial_scope_adds_only_missing_action_preserving_policy(self):
        self.native.grants.append(
            {"id": "partial", **M.scopes(POLICY)[0], "actions": ["run"]}
        )
        before = copy.deepcopy(self.native.grants)
        result = self.apply()
        self.assertEqual(
            [["read"], ["write", "read"]], [g["actions"] for g in result["created"]]
        )
        self.assertTrue(all(g in self.native.grants for g in before))
        self.assertTrue(result["groups_unchanged"])
        self.assertEqual("applied", result["state"])
        self.assertNotIn("configured_gateway_token_proof", result)
        self.assertEqual(hashlib.sha256(RAW).hexdigest(), result["manifest_sha256"])
        self.assertEqual(
            before,
            json.loads(
                (pathlib.Path(result["backup_path"]) / "grants-before.json").read_text()
            ),
        )

    def test_conflict_rejected_before_any_mutation(self):
        self.native.grants.append(
            {"id": "broad", **M.scopes(POLICY)[0], "actions": ["run", "read", "write"]}
        )
        with self.assertRaisesRegex(ValueError, "scope_conflict"):
            self.apply()
        self.assertFalse(any("create" in c or "reload" in c for c in self.native.calls))

    def test_missing_ambiguous_or_invalid_registration_prevents_mutation(self):
        for rows in [
            [],
            [{"name": "operation-example", "id": "-" * 36}],
            [{"name": "operation-example", "id": UUID}] * 2,
        ]:
            self.native.catalog["results"] = rows
            with self.assertRaisesRegex(ValueError, "registration_unproved"):
                self.apply()
        self.assertFalse(any("create" in c for c in self.native.calls))

    def test_uncertain_create_or_reload_never_repeated(self):
        for failure in ["create", "reload"]:
            with (
                self.subTest(failure=failure),
                tempfile.TemporaryDirectory() as directory,
            ):
                native = Native()
                native.fail = failure
                with self.assertRaises(TimeoutError):
                    M.apply(RAW, SHA, pathlib.Path(directory), native)
                checkpoint = json.loads(
                    (pathlib.Path(directory) / (SHA + ".json")).read_text()
                )
                self.assertEqual("failed_requires_inspection", checkpoint["state"])
                self.assertIn("pending_mutation", checkpoint)
                calls = len(native.calls)
                with self.assertRaisesRegex(
                    ValueError, "checkpoint_requires_inspection"
                ):
                    M.apply(RAW, SHA, pathlib.Path(directory), native)
                self.assertEqual(calls, len(native.calls))

    def test_applied_checkpoint_continues_with_reads_only(self):
        self.apply()
        self.native.calls = []
        result = self.apply()
        self.assertTrue(result["read_only_continuation"])
        self.assertEqual([], result["created"])
        self.assertFalse(any("create" in c or "reload" in c for c in self.native.calls))

    def test_changed_applied_policy_refuses_without_mutation(self):
        self.apply()
        self.native.grants.pop()
        self.native.calls = []
        with self.assertRaisesRegex(ValueError, "applied_policy_changed"):
            self.apply()
        self.assertFalse(any("create" in c for c in self.native.calls))

    def test_current_full_source_manifest(self):
        raw = (
            pathlib.Path(__file__).parents[2] / "config/gateway-workflow-grants.json"
        ).read_bytes()
        policy = json.loads(raw)
        scopes = M.scopes(policy)
        self.assertEqual(len(policy["workflows"]) + len(policy["vaults"]), len(scopes))
        self.assertTrue(all(r["resource"]["pattern"] != "*" for r in scopes))

    def test_duplicate_scopes_and_invalid_actions_refused(self):
        for policy in [
            dict(POLICY, workflows=POLICY["workflows"] * 2),
            dict(POLICY, principal="user:wrong"),
            dict(POLICY, workflows=[{"workflow": "*", "actions": ["run", "read"]}]),
        ]:
            with self.assertRaises(ValueError):
                M.scopes(policy)
