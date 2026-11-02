"""database:server:destroy phases against a recorded Docker CLI: every Process container, never the volume."""

import subprocess
import sys
import unittest
from pathlib import Path
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).with_name("server")))
import destroy

INTENT = {"process_id": 7, "container": "orbit-process-7-app"}


class Docker:
    def __init__(self, answers):
        self.answers, self.calls = answers, []

    def __call__(self, *argv):
        self.calls.append(list(argv))
        for prefix, reply in self.answers.items():
            if list(argv[: len(prefix.split())]) == prefix.split():
                code, stdout = reply.pop(0) if isinstance(reply, list) else reply
                return subprocess.CompletedProcess(argv, code, stdout, "")
        return subprocess.CompletedProcess(argv, 0, "", "")


class ServerDestroy(unittest.TestCase):
    def phase(self, name, answers):
        docker = Docker(answers)
        with patch.object(destroy, "docker", docker):
            try:
                return getattr(destroy, name)(INTENT), docker
            except destroy.Refused as error:
                return {"error_code": error.code}, docker

    def test_remove_stops_and_removes_every_container_of_the_process_and_keeps_volumes(
        self,
    ):
        result, docker = self.phase(
            "remove",
            {
                "ps": (0, "b" * 64 + "\n" + "a" * 64 + "\n"),
                "container inspect": (0, "a" * 64 + " 7\n"),
            },
        )
        self.assertEqual(result, {"removed": True})
        removed = [call for call in docker.calls if call[0] in ("stop", "rm")]
        self.assertEqual(
            removed,
            [
                ["stop", "--time", "60", "a" * 64],
                ["rm", "a" * 64],
                ["stop", "--time", "60", "b" * 64],
                ["rm", "b" * 64],
            ],
        )
        self.assertFalse(any("volume" in call for call in docker.calls))

    def test_remove_refuses_a_named_container_of_another_process_before_any_change(
        self,
    ):
        result, docker = self.phase(
            "remove", {"ps": (0, "a" * 64), "container inspect": (0, "c" * 64 + " 8\n")}
        )
        self.assertEqual(result, {"error_code": "database.server_container_foreign"})
        self.assertFalse(any(call[0] in ("stop", "rm") for call in docker.calls))

    def test_gone_fails_while_a_container_is_left(self):
        result, _ = self.phase(
            "gone", {"ps": (0, "a" * 64), "container inspect": (1, "")}
        )
        self.assertEqual(result, {"error_code": "database.server_remove_incomplete"})
        result, _ = self.phase("gone", {"ps": (0, ""), "container inspect": (1, "")})
        self.assertEqual(result, {"absent": True})


if __name__ == "__main__":
    unittest.main()
