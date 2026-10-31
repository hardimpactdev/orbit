"""The shipped protocol library keeps authentication material inside its process."""

import importlib.util
import json
import os
import signal
import socket
import threading
import time
import unittest
from pathlib import Path
from unittest.mock import patch

spec = importlib.util.spec_from_file_location(
    "vault_sync_client", Path(__file__).with_name("client.py")
)
client = importlib.util.module_from_spec(spec)
spec.loader.exec_module(client)


class ClientTest(unittest.TestCase):
    def test_authenticated_crypto_roundtrip_and_tamper_refusal(self):
        crypto = client.Crypto()
        encrypted = crypto.encrypt("private-value", b"k" * 64)
        self.assertNotIn("private-value", encrypted)
        self.assertEqual(crypto.decrypt(encrypted, b"k" * 64), b"private-value")
        with self.assertRaises(client.Refusal):
            crypto.decrypt(encrypted, b"z" * 64)

    def test_configuration_binds_admitted_scope_and_consumes_secret_environment(self):
        intent = {
            "url": "https://vault.orbit",
            "client_id": "client",
            "device_identifier": "device",
            "organization_id": "org",
            "collection_id": "collection",
            "ca_file": "/public/ca.pem",
        }
        protected = {**intent, "client_secret": "private-value"}
        with patch.dict(os.environ, ORBIT_VAULT_CONFIGURATION=json.dumps(protected)):
            self.assertEqual(
                client.configuration(intent)["client_secret"], "private-value"
            )
            self.assertNotIn("ORBIT_VAULT_CONFIGURATION", os.environ)
        with patch.dict(os.environ, ORBIT_VAULT_CONFIGURATION=json.dumps(protected)):
            with self.assertRaises(client.Refusal) as error:
                client.configuration({**intent, "collection_id": "foreign"})
            self.assertEqual(error.exception.code, "vault.intent_changed")

    def test_public_output_rejects_escaped_credentials_tokens_and_decrypted_keys(self):
        config = {
            "master_password": 'private"password',
            "client_secret": "private-client",
        }
        remote = type(
            "Session", (), {"token": "private-bearer", "keys": {"org": b"k" * 64}}
        )()
        for secret in [
            config["master_password"],
            config["client_secret"],
            remote.token,
            (b"k" * 64).hex(),
        ]:
            with self.subTest(secret_type=type(secret).__name__):
                with self.assertRaises(client.Refusal) as error:
                    client.public(
                        {"nested": ["prefix-" + secret + "-suffix"]}, config, remote
                    )
                self.assertEqual(error.exception.code, "vault.result_invalid")
        self.assertEqual(
            client.public({"id": "cipher"}, config, remote), {"id": "cipher"}
        )

    def test_request_deadline_includes_a_body_that_keeps_making_progress(self):
        receiver, sender = socket.socketpair()
        stopped = threading.Event()

        def drip():
            while not stopped.wait(0.01):
                try:
                    sender.sendall(b" ")
                except OSError:
                    return

        writer = threading.Thread(target=drip)
        writer.start()
        timer = signal.setitimer
        opener = type(
            "Opener", (), {"open": lambda *args, **kwargs: receiver.makefile("rb")}
        )()
        remote = client.Collection(
            {"url": "https://vault.orbit", "ca_file": "/public/ca.pem"}
        )
        started = time.monotonic()
        try:
            with (
                patch.object(
                    client.ssl, "create_default_context", return_value=object()
                ),
                patch.object(
                    client.urllib.request, "build_opener", return_value=opener
                ),
                patch.object(
                    client.signal,
                    "setitimer",
                    side_effect=lambda which, seconds: timer(
                        which, 0.15 if seconds else 0
                    ),
                ),
            ):
                with self.assertRaises(client.Refusal) as error:
                    remote.request("GET", "/api/sync")
                self.assertEqual(error.exception.code, "vault.unreachable")
            self.assertLess(time.monotonic() - started, 1)
        finally:
            stopped.set()
            receiver.close()
            sender.close()
            writer.join(1)


if __name__ == "__main__":
    unittest.main()
