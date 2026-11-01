"""The proof observer rejects unchanged-key-only and unrelated-socket loss."""

import importlib.util
import io
import json
import os
import shlex
import struct
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import Mock, patch

SPEC = importlib.util.spec_from_file_location(
    "observer", Path(__file__).with_name("socket_observer.py")
)
observer = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(observer)


class SocketObserverTests(unittest.TestCase):
    def test_reads_server_close_frame_and_masks_its_acknowledgement(self):
        connection = observer.WebSocket.__new__(observer.WebSocket)
        connection.stream = io.BytesIO(b"\x88\x02" + struct.pack("!H", 4201))
        connection.socket = Mock()
        opcode, payload = connection.frame()
        self.assertEqual((8, struct.pack("!H", 4201)), (opcode, payload))
        connection.send(opcode, payload)
        frame = connection.socket.sendall.call_args.args[0]
        self.assertEqual(b"\x88\x82", frame[:2])
        mask = frame[2:6]
        self.assertEqual(
            payload,
            bytes(value ^ mask[index % 4] for index, value in enumerate(frame[6:])),
        )

    def test_eof_and_oversized_server_frame_refuse_observation(self):
        for frame in (b"", b"\x88\x02x", b"\x81\x7f" + struct.pack("!Q", 65537)):
            connection = observer.WebSocket.__new__(observer.WebSocket)
            connection.stream = io.BytesIO(frame)
            with self.subTest(frame=frame), self.assertRaises(AssertionError):
                connection.frame()

    def test_readiness_blocks_on_one_scoped_journal_stream_and_cleans_up(self):
        reader = Mock()
        reader.stdout = iter(["earlier message\n", "WS_PROOF_READY:nonce\n"])
        with (
            patch.object(observer.subprocess, "Popen", return_value=reader) as process,
            patch("builtins.print") as output,
        ):
            observer.wait_journal("ws-rot-name", "nonce", "ready")
        self.assertIn("--unit=orbit-e2e-ws-rot-name.service", process.call_args.args[0])
        output.assert_called_once_with("WS_PROOF_READY:nonce", flush=True)
        reader.terminate.assert_called_once()
        reader.wait.assert_called_once_with(timeout=2)

    def test_ended_stream_cannot_certify_ready(self):
        reader = Mock()
        reader.stdout = iter(["WS_PROOF_READY:other\n"])
        with (
            patch.object(observer.subprocess, "Popen", return_value=reader),
            self.assertRaises(AssertionError),
        ):
            observer.wait_journal("ws-rot-name", "nonce", "ready")
        reader.terminate.assert_called_once()

    def sockets(self, code=4201, unrelated_opcode=10):
        subject, unrelated = Mock(), Mock()
        subject.frame.return_value = (8, struct.pack("!H", code))
        unrelated.frame.return_value = (unrelated_opcode, b"orbit-proof")
        return subject, unrelated

    def test_rotation_closes_subject_with_4201_and_other_answers_ping(self):
        subject, unrelated = self.sockets()
        with (
            patch.object(observer, "WebSocket", side_effect=[subject, unrelated]),
            patch("builtins.print") as output,
        ):
            observer.observe("subject-public-key", "other-public-key", "public-tag")
        subject.send.assert_called_once_with(8, struct.pack("!H", 4201))
        unrelated.send.assert_called_once_with(9, b"orbit-proof")
        self.assertIn(
            "WS_PROOF_ROTATION_PASS:public-tag",
            [call.args[0] for call in output.call_args_list],
        )

    def test_wrong_close_code_refuses_certification(self):
        subject, unrelated = self.sockets(code=1000)
        with (
            patch.object(observer, "WebSocket", side_effect=[subject, unrelated]),
            patch("builtins.print") as output,
            self.assertRaises(AssertionError),
        ):
            observer.observe("subject-public-key", "other-public-key", "public-tag")
        self.assertNotIn(
            "WS_PROOF_ROTATION_PASS:public-tag",
            [call.args[0] for call in output.call_args_list],
        )

    def test_unrelated_socket_close_refuses_certification(self):
        subject, unrelated = self.sockets(unrelated_opcode=8)
        with (
            patch.object(observer, "WebSocket", side_effect=[subject, unrelated]),
            patch("builtins.print") as output,
            self.assertRaises(AssertionError),
        ):
            observer.observe("subject-public-key", "other-public-key", "public-tag")
        self.assertNotIn(
            "WS_PROOF_ROTATION_PASS:public-tag",
            [call.args[0] for call in output.call_args_list],
        )

    def test_missing_ready_observation_stops_before_the_request(self):
        with (
            tempfile.TemporaryDirectory() as folder,
            patch.object(observer.tempfile, "gettempdir", return_value=folder),
            patch.object(observer, "topology") as topology,
        ):
            topology.side_effect = [
                {"state": "spawned"},
                {"state": "executed", "exit_code": 124},
            ]
            with self.assertRaisesRegex(
                RuntimeError, "websocket.proof_readiness_failed"
            ):
                observer.host("start", "run", "subject-public-key", "other-public-key")

    def test_old_observer_success_cannot_certify_this_request(self):
        with (
            tempfile.TemporaryDirectory() as folder,
            patch.object(observer.tempfile, "gettempdir", return_value=folder),
            patch.object(observer, "topology") as topology,
        ):
            Path(folder, "orbit-proof-ws-rotate-run.json").write_text(
                '{"name":"ws-rot-new","nonce":"new"}'
            )
            topology.side_effect = [
                {"state": "executed", "exit_code": 0},
                {"state": "logs", "output": "WS_PROOF_ROTATION_PASS:old"},
            ]
            with self.assertRaises(AssertionError):
                observer.host("check", "run")

    def test_fixture_hook_executes_from_the_kind_directory_before_admission(self):
        kind_dir = Path(__file__).parents[1]
        fixture = json.loads(Path(str(kind_dir) + ".json").read_text())
        argv = [
            part.replace("${capture.subject_key}", "subject-public-key").replace(
                "${capture.unrelated_key}", "other-public-key"
            )
            for part in fixture["before_request"][0]["argv"]
        ]
        with tempfile.TemporaryDirectory() as folder:
            driver = Path(folder, "topology_driver.py")
            driver.write_text("""import json
import sys
action = sys.argv[1]
if action == 'spawn':
    result = {'state': 'spawned'}
elif action == 'exec':
    result = {'state': 'executed', 'exit_code': 0, 'stdout': ''}
elif action == 'logs':
    nonce = sys.argv[4].removeprefix('ws-rot-')
    result = {'state': 'logs', 'output': 'WS_PROOF_READY:' + nonce}
else:
    raise AssertionError('Unsupported topology action')
print(json.dumps(result))
""")
            environment = {
                name: value
                for name, value in os.environ.items()
                if not name.startswith("PROOF_")
            }
            environment.update(
                {
                    "PROOF_RUN": "fixture-run",
                    "PROOF_KIND_DIR": str(kind_dir),
                    "PROOF_EXEC": shlex.join(
                        [sys.executable, str(driver), "exec", "fixture"]
                    ),
                    "TMPDIR": folder,
                }
            )
            completed = subprocess.run(
                argv,
                cwd=kind_dir,
                env=environment,
                capture_output=True,
                text=True,
                timeout=10,
                check=False,
            )
        self.assertEqual(0, completed.returncode, completed.stderr)
        self.assertIn("F13 socket observer start: PASS", completed.stdout)

    def test_before_request_uses_run_identity_without_an_operation_id(self):
        with (
            patch.dict(
                "os.environ",
                {"PROOF_RUN": "59f7512a-3ee9-44f9-ae1b-1101b99f55cc"},
                clear=True,
            ),
            patch(
                "sys.argv",
                ["observer.py", "start", "subject-public-key", "other-public-key"],
            ),
            patch.object(observer, "host") as host,
        ):
            self.assertEqual(0, observer.main())
        host.assert_called_once_with(
            "start",
            "59f7512a-3ee9-44f9-ae1b-1101b99f55cc",
            "subject-public-key",
            "other-public-key",
        )

    def test_observer_without_rotation_expires_instead_of_passing(self):
        subject, unrelated = self.sockets()
        subject.frame.side_effect = TimeoutError()
        with (
            patch.object(observer, "WebSocket", side_effect=[subject, unrelated]),
            patch(
                "sys.argv",
                [
                    "observer",
                    "observe",
                    "run",
                    "subject-public-key",
                    "other-public-key",
                ],
            ),
            patch("builtins.print") as output,
        ):
            self.assertEqual(1, observer.main())
        self.assertIn(
            "WS_PROOF_ROTATION_FAILED:run",
            [call.args[0] for call in output.call_args_list],
        )


if __name__ == "__main__":
    unittest.main()
