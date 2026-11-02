"""Whole-hub peer publication against a sandbox /etc/wireguard; no network, service or live effects."""

import importlib.util
import tempfile
import unittest
from pathlib import Path

MODULE = Path(__file__).with_name("hub_publish.py")
spec = importlib.util.spec_from_file_location("hub_publish", MODULE)
hub = importlib.util.module_from_spec(spec)
spec.loader.exec_module(hub)
A = "A" * 43 + "="
B = "B" * 43 + "="
PRIVATE = "P" * 43 + "="
INTERFACE = f"[Interface]\nAddress = 10.44.0.1/24\nListenPort = 51820\nPrivateKey = {PRIVATE}\n"


def peers(*keys):
    return [{"name": f"node-{i}", "public_key": key, "address": f"10.44.0.{i + 2}"} for i, key in enumerate(keys)]


class Service:
    """systemctl, wg-quick and wg, as the script calls them through ok() and peers_match()."""

    def __init__(self, active=True, sync=True):
        self.active, self.sync, self.calls = active, sync, []

    def ok(self, *argv, stdout=None):
        self.calls.append(argv)
        if argv[:2] == ("systemctl", "is-active"):
            return self.active
        if argv[:2] == ("wg", "syncconf"):
            return self.sync
        return True


class HubPublishTests(unittest.TestCase):
    def setUp(self):
        folder = Path(tempfile.mkdtemp())
        self.addCleanup(lambda: [p.unlink() for p in folder.iterdir()] and folder.rmdir())
        hub.WG, hub.LIVE, hub.BACKUP = folder, folder / "orbit.conf", folder / ".orbit.conf.rollback"
        self.service = Service()
        hub.ok = self.service.ok
        hub.peers_match = lambda config: True

    def write(self, text):
        hub.LIVE.write_text(text)

    def test_keeps_the_interface_and_key_and_replaces_only_peers(self):
        self.write(INTERFACE + f"\n[Peer]\n# old\nPublicKey = {B}\nAllowedIPs = 10.44.0.9/32\n")
        hub.publish(peers(A))
        live = hub.LIVE.read_text()
        self.assertTrue(live.startswith(INTERFACE.rstrip("\n")))
        self.assertIn(A, live)
        self.assertNotIn(B, live)
        self.assertIn(("wg", "syncconf", "orbit"), [call[:3] for call in self.service.calls])
        self.assertFalse(hub.BACKUP.exists())

    def test_an_identical_running_list_writes_nothing(self):
        _, config = (self.write(INTERFACE), hub.render(peers(A)))[1]
        self.write(config)
        hub.publish(peers(A))
        self.assertEqual(hub.LIVE.read_text(), config)
        self.assertNotIn(("systemctl", "enable", hub.SERVICE), self.service.calls)

    def test_a_failed_sync_restores_the_previous_file(self):
        previous = INTERFACE + f"\n[Peer]\n# old\nPublicKey = {B}\nAllowedIPs = 10.44.0.9/32\n"
        self.write(previous)
        self.service.sync = False
        with self.assertRaises(hub.Refusal) as refused:
            hub.publish(peers(A))
        self.assertEqual(refused.exception.code, "wireguard.hub_start_failed")
        self.assertEqual(hub.LIVE.read_text(), previous)

    def test_refuses_hooks_and_a_hub_without_its_own_file(self):
        with self.assertRaises(hub.Refusal) as missing:
            hub.publish(peers(A))
        self.assertEqual(missing.exception.code, "wireguard.hub_state_unsupported")
        self.write(INTERFACE + "PostUp = iptables -A FORWARD\n")
        with self.assertRaises(hub.Refusal) as hook:
            hub.publish(peers(A))
        self.assertEqual(hook.exception.code, "wireguard.hub_config_invalid")


if __name__ == "__main__":
    unittest.main()
