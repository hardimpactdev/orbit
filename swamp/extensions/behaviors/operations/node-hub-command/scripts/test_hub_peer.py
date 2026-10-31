"""Isolated hub transactions; no network, DNS, service, or live filesystem effects."""

import importlib.util
import tempfile
import unittest
from pathlib import Path

MODULE = Path(__file__).with_name("hub_peer.py")
spec = importlib.util.spec_from_file_location("hub_peer", MODULE)
hub = importlib.util.module_from_spec(spec)
spec.loader.exec_module(hub)
A = "A" * 43 + "="
B = "B" * 43 + "="
C = "C" * 43 + "="
PRIVATE = "P" * 43 + "="
BASE = (
    f"[Interface]\nAddress = 10.44.0.1/24\nListenPort = 51820\nPrivateKey = {PRIVATE}\n"
)


def peer(key=A, address="10.44.0.2", node_id=2):
    return {"node_id": node_id, "name": "guest", "address": address, "public_key": key}


def config(*peers):
    return BASE + "".join(
        f"\n[Peer]\n# {p['name']}\nPublicKey = {p['public_key']}\nAllowedIPs = {p['address']}/32\n"
        for p in peers
    )


def action(before, after):
    return {
        "schema_version": 1,
        "hub_id": 1,
        "hub_address": "10.44.0.1/24",
        "port": 51820,
        "restoring": False,
        "context_node_id": 2,
        "context_address": "10.44.0.2",
        "before": before,
        "after": after,
    }


class HubPeerTests(unittest.TestCase):
    def test_remove_and_restore_preserve_a_newer_unrelated_peer(self):
        old = peer()
        newer = peer(B, "10.44.0.3", 3)
        removed, changed = hub.render(config(old, newer), action(old, None))
        self.assertTrue(changed)
        self.assertNotIn(A, removed)
        newest = peer(C, "10.44.0.4", 4)
        current = removed + config(newest).split("[Peer]", 1)[1].join(["[Peer]", ""])
        restored, changed = hub.render(current, action(None, old))
        self.assertTrue(changed)
        self.assertIn(A, restored)
        self.assertIn(B, restored)
        self.assertIn(C, restored)
        self.assertIn(PRIVATE, restored)

    def test_prior_absence_stays_absent_on_remove_and_restore(self):
        self.assertEqual(hub.render(BASE, action(None, None)), (BASE, False))

    def test_prepared_key_changes_only_context_and_resume_is_idempotent(self):
        old, new = peer(), peer(C)
        desired, changed = hub.render(
            config(old, peer(B, "10.44.0.3", 3)), action(old, new)
        )
        self.assertTrue(changed)
        self.assertNotIn(A, desired)
        self.assertIn(B, desired)
        self.assertEqual(hub.render(desired, action(old, new)), (desired, False))

    def test_stale_same_context_and_foreign_address_refuse(self):
        for current in [
            config(peer(B)),
            BASE,
            config(peer(A), peer(C, "10.44.0.3", 3)),
        ]:
            with self.assertRaises(hub.Refusal):
                hub.render(current, action(peer(A), peer(C)))

    def test_foreign_hooks_and_duplicate_peers_refuse(self):
        for current in [
            BASE.replace("[Interface]", "[Interface]\nPostUp = evil"),
            config(peer(), peer()),
        ]:
            with self.assertRaises(hub.Refusal):
                hub.render(current, action(peer(), None))

    def test_failed_sync_restores_file_and_public_result_has_no_private_key(self):
        with tempfile.TemporaryDirectory() as directory:
            old_wg, old_uid = hub.WG, hub.UID
            old_command = hub.command
            hub.WG, hub.UID = Path(directory), __import__("os").getuid()
            hub.WG.chmod(0o700)
            original = config(peer())
            (hub.WG / "orbit.conf").write_text(original)
            (hub.WG / "orbit.conf").chmod(0o600)
            calls = []

            def run(args):
                calls.append(args)
                if args[0] == "/usr/bin/wg-quick":
                    return b"[Interface]\nPrivateKey = synthetic\n"
                if (
                    args[0] == "/usr/bin/wg"
                    and sum(x[0] == "/usr/bin/wg" for x in calls) == 1
                ):
                    raise hub.Refusal("wireguard.hub_start_failed")
                return b""

            hub.command = run
            try:
                with self.assertRaises(hub.Refusal):
                    hub.apply(action(peer(), None))
                self.assertEqual((hub.WG / "orbit.conf").read_text(), original)
                result = hub.apply(action(peer(), None))
                self.assertNotIn(PRIVATE, str(result))
                self.assertEqual(
                    sorted(p.name for p in hub.WG.iterdir()), ["orbit.conf"]
                )
            finally:
                hub.WG, hub.UID, hub.command = old_wg, old_uid, old_command


class FilesystemRollbackTests(unittest.TestCase):
    def test_filesystem_failure_after_publish_restores_prior_file(self):
        with tempfile.TemporaryDirectory() as directory:
            old_wg, old_uid, old_command, old_sync = (
                hub.WG,
                hub.UID,
                hub.command,
                hub.sync_config,
            )
            hub.WG, hub.UID = Path(directory), __import__("os").getuid()
            hub.WG.chmod(0o700)
            original = config(peer())
            path = hub.WG / "orbit.conf"
            path.write_text(original)
            path.chmod(0o600)
            hub.command = lambda _args: b""
            calls = []

            def sync(text):
                calls.append(text)
                if len(calls) == 1:
                    raise OSError("synthetic disk failure")

            hub.sync_config = sync
            try:
                with self.assertRaises(hub.Refusal):
                    hub.apply(action(peer(), None))
                self.assertEqual(path.read_text(), original)
                self.assertEqual(calls[-1], original)
            finally:
                hub.WG, hub.UID, hub.command, hub.sync_config = (
                    old_wg,
                    old_uid,
                    old_command,
                    old_sync,
                )

    def test_peer_cannot_claim_hub_address(self):
        with self.assertRaises(hub.Refusal):
            hub.render(BASE, action(None, peer(A, "10.44.0.1")))


if __name__ == "__main__":
    unittest.main()
