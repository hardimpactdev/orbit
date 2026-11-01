import contextlib
import io
import json
import pathlib
import subprocess
import tempfile
import types
import unittest
from unittest.mock import Mock, patch

import guarded_registry_restart as R
import interlocked_load as I
import loader as L

SHA = "a" * 40
AUDIT = {
    "checked": 1,
    "matched": 1,
    "errors": [],
    "mismatches": [],
    "source_not_served": [],
    "served_without_source": [],
}


class Acceptance(unittest.TestCase):
    def test_deleted_source_exception_is_accepted_but_current_mismatch_or_unknown_is_not(
        self,
    ):
        deleted = {
            **AUDIT,
            "served_without_source": ["@retired/example"],
            "errors": [{"type": "@retired/example", "error": "served_without_source"}],
        }
        self.assertTrue(L.audit_accepted(deleted))
        self.assertFalse(
            L.audit_accepted({**deleted, "mismatches": [{"type": "@current/example"}]})
        )
        self.assertFalse(L.audit_accepted({**AUDIT, "checked": 0, "matched": 0}))
        self.assertFalse(
            L.audit_accepted({**AUDIT, "source_not_served": ["@current/missing"]})
        )
        self.assertFalse(
            L.audit_accepted({**AUDIT, "errors": [{"error": "unreadable"}]})
        )

    def test_ready_feed_remains_until_verified_configured_token_proof(self):
        with tempfile.TemporaryDirectory() as directory:
            root = pathlib.Path(directory)
            (root / "config").mkdir()
            manifest_file = root / "config/gateway-workflow-grants.json"
            manifest_file.write_text("{}")
            manifest = {"target": SHA}
            (root / "ready.json").write_text(json.dumps(manifest))
            proof = {
                "loaded_sha": SHA,
                "behavior_versions": AUDIT,
                "manifest_grants": {
                    "state": "applied",
                    "target": SHA,
                    "manifest_sha256": L.digest(manifest_file),
                },
            }
            with (
                patch.object(L, "STATE", root),
                patch.object(L, "APP", root),
                patch.object(L, "FEED", root),
            ):
                for bad in [
                    {},
                    {"principal": "user:orbit-gateway"},
                    {"principal": "user:orbit-gateway", "verified": False},
                    {"principal": "other", "verified": True},
                ]:
                    proof["manifest_grants"]["state"] = "verified"
                    proof["manifest_grants"]["configured_gateway_token_proof"] = bad
                    (root / "last-proof.json").write_text(json.dumps(proof))
                    self.assertFalse(L.consume_accepted_feed(manifest, SHA))
                    self.assertTrue((root / "ready.json").exists())
                proof["manifest_grants"]["configured_gateway_token_proof"] = {
                    "principal": "user:orbit-gateway",
                    "verified": True,
                }
                (root / "last-proof.json").write_text(json.dumps(proof))
                self.assertTrue(L.consume_accepted_feed(manifest, SHA))
                self.assertFalse((root / "ready.json").exists())

    def test_all_publication_modes_defer_before_source_merge_when_quiet_is_held(self):
        for mode in ["restart", "hot_reload", "source_only"]:
            with self.subTest(mode=mode), tempfile.TemporaryDirectory() as directory:
                root = pathlib.Path(directory)
                (root / "ready.json").write_text(json.dumps({"target": SHA}))
                spec = types.SimpleNamespace(
                    loader=types.SimpleNamespace(
                        exec_module=lambda checks: setattr(
                            checks,
                            "drain_before_restart",
                            lambda host: {"state": "restart_deferred_active_runs"},
                        )
                    )
                )
                git = Mock(return_value="b" * 40)
                with (
                    patch.object(L, "STATE", root),
                    patch.object(L, "FEED", root),
                    patch.object(L, "LOCK", root / "lock"),
                    patch.object(L, "git", git),
                    patch.object(L, "preflight", return_value=(SHA, set())),
                    patch.object(
                        L,
                        "serve_runtime",
                        return_value={"remote_only": True, "scheduled_runs": 1},
                    ),
                    patch.object(L, "reload_plan", return_value={"mode": mode}),
                    patch.object(
                        L.importlib.util, "spec_from_file_location", return_value=spec
                    ),
                    patch.object(
                        L.importlib.util,
                        "module_from_spec",
                        return_value=types.SimpleNamespace(),
                    ),
                    contextlib.redirect_stdout(io.StringIO()) as output,
                ):
                    L.main(
                        {
                            "gateway_release_lock": "GatewayReleaseLock::run",
                            "gateway_auto_status_checked": True,
                            "gateway_active_release_count": 0,
                            "announcement": "test",
                        }
                    )
                self.assertNotIn(
                    ("merge", "--ff-only", SHA), [c.args for c in git.call_args_list]
                )
                self.assertIn("restart_deferred_active_runs", output.getvalue())

    def test_generated_gateway_script_lints_and_never_exposes_transport_error(self):
        script = I.gateway_script("a quoted announcement $HOME `not shell`")
        result = subprocess.run(
            ["php", "-l"], input=script, text=True, check=False, capture_output=True
        )
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn("GatewayReleaseLock::class)->run", script)
        with (
            patch.object(
                I.subprocess,
                "run",
                return_value=types.SimpleNamespace(
                    returncode=1, stderr="SECRET", stdout="SECRET"
                ),
            ),
            self.assertRaisesRegex(
                RuntimeError,
                "^gateway_interlocked_load_refused_or_uncertain_inspect_checkpoints$",
            ),
        ):
            I.run("test")

    def test_native_active_metadata_refusal_is_never_drain_proof(self):
        with self.assertRaises(AssertionError):
            R.drain_reply({"type": "error"})
        self.assertIsNotNone(
            R.drain_reply(
                {"type": "run.history", "payload": {"runs": []}},
                {"state": "QUIET_HELD"},
            )
        )
        self.assertIsNone(
            R.drain_reply(
                {"type": "run.history", "payload": {"runs": []}},
                {"state": "QUIET_RELEASED"},
            )
        )

    def test_unaccepted_runtime_proof_does_not_advance_publisher_accepted_pointer(self):
        with tempfile.TemporaryDirectory() as directory:
            root = pathlib.Path(directory)
            raw = b"{}"
            (root / "config").mkdir()
            (root / "config/gateway-workflow-grants.json").write_bytes(raw)
            accepted = {"loaded_sha": "b" * 40}
            (root / "last-proof.json").write_text(json.dumps(accepted))
            (root / "runtime-proof.json").write_text(
                json.dumps(
                    {
                        "loaded_sha": SHA,
                        "behavior_versions": AUDIT,
                        "swamp_tree_sha": "c" * 40,
                    }
                )
            )
            policy = types.SimpleNamespace(
                apply=Mock(return_value={"state": "applied"}), native=Mock()
            )
            with (
                patch.object(L, "STATE", root),
                patch.object(L, "APP", root),
                patch.object(L, "manifest_module", return_value=policy),
            ):
                self.assertEqual(
                    "loaded_grants_pending", L.prepare_manifest_grants(SHA)["state"]
                )
            self.assertEqual(
                accepted, json.loads((root / "last-proof.json").read_text())
            )
            self.assertEqual(
                "configured_gateway_token_proof_pending",
                json.loads((root / "runtime-proof.json").read_text())["acceptance"][
                    "state"
                ],
            )

    def test_pending_token_proof_continues_without_restarting_serve(self):
        with tempfile.TemporaryDirectory() as directory:
            root = pathlib.Path(directory)
            proof = {
                "loaded_sha": SHA,
                "runtime_after": {"pid": 2},
                "acceptance": {"state": "configured_gateway_token_proof_pending"},
            }
            (root / "runtime-proof.json").write_text(json.dumps(proof))
            with (
                patch.object(L, "STATE", root),
                patch.object(L, "serve_runtime", return_value={"pid": 2}),
                patch.object(L, "audit_behavior_versions", return_value=AUDIT),
                patch.object(L, "clean_source"),
            ):
                self.assertTrue(L.policy_proof_pending(SHA))
                self.assertFalse(L.policy_proof_pending("b" * 40))

    def test_timer_has_no_effect_without_gateway_interlock(self):
        with (
            patch.object(L, "git", side_effect=AssertionError("effect")),
            contextlib.redirect_stdout(io.StringIO()),
        ):
            L.main()


if __name__ == "__main__":
    unittest.main()
