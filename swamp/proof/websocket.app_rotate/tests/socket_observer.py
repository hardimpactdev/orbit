"""F13 test observer: existing sockets, managed listener, topology spawn/logs only."""

import json
import os
import re
import shlex
import socket
import struct
import subprocess
import sys
import tempfile
import uuid
from pathlib import Path


class WebSocket:
    def __init__(self, key):
        self.socket = socket.create_connection(("127.0.0.1", 8790), timeout=60)
        self.stream = self.socket.makefile("rb")
        self.socket.sendall(
            (
                "GET /app/" + key + "?protocol=7&client=js&version=8.4.0 HTTP/1.1\r\n"
                "Host: localhost\r\nConnection: Upgrade\r\nUpgrade: websocket\r\n"
                "Sec-WebSocket-Version: 13\r\nSec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n\r\n"
            ).encode()
        )
        assert b"101 Switching Protocols" in self.stream.readline(4096)
        for _ in range(64):
            line = self.stream.readline(4096)
            if line == b"\r\n":
                break
            assert line
        else:
            raise AssertionError("Handshake headers exceed the test limit")
        opcode, payload = self.frame()
        assert (
            opcode == 1
            and json.loads(payload)["event"] == "pusher:connection_established"
        )

    def exact(self, count):
        value = self.stream.read(count)
        if len(value) != count:
            raise AssertionError("WebSocket ended without the expected frame")
        return value

    def frame(self):
        first, second = self.exact(2)
        assert not second & 128  # Server frames are never masked.
        size = second & 127
        if size == 126:
            size = struct.unpack("!H", self.exact(2))[0]
        elif size == 127:
            size = struct.unpack("!Q", self.exact(8))[0]
        assert size <= 65536
        return first & 15, self.exact(size)

    def send(self, opcode, payload):
        assert len(payload) < 126
        mask = os.urandom(4)
        frame = bytes([128 | opcode, 128 | len(payload)]) + mask
        self.socket.sendall(
            frame
            + bytes(value ^ mask[index % 4] for index, value in enumerate(payload))
        )

    def close(self):
        self.stream.close()
        self.socket.close()


def observe(subject_key, unrelated_key, tag):
    subject = WebSocket(subject_key)
    unrelated = WebSocket(unrelated_key)
    try:
        print("WS_PROOF_READY:" + tag, flush=True)
        for _ in range(16):
            opcode, payload = subject.frame()
            if opcode == 8:
                assert len(payload) >= 2 and struct.unpack("!H", payload[:2])[0] == 4201
                subject.send(8, payload)
                subject.close()
                break
            if opcode == 9:
                subject.send(10, payload)
        else:
            raise AssertionError("No rotation close frame")
        unrelated.send(9, b"orbit-proof")
        for _ in range(16):
            opcode, payload = unrelated.frame()
            assert opcode != 8
            if opcode == 10 and payload == b"orbit-proof":
                print("WS_PROOF_ROTATION_PASS:" + tag, flush=True)
                break
            if opcode == 9:
                unrelated.send(10, payload)
        else:
            raise AssertionError("The unrelated socket did not answer ping")
    finally:
        subject.close()
        unrelated.close()


def wait_journal(name, nonce, phase):
    assert phase in ("ready", "pass")
    marker = (
        "WS_PROOF_READY:" if phase == "ready" else "WS_PROOF_ROTATION_PASS:"
    ) + nonce
    reader = subprocess.Popen(
        [
            "sudo",
            "-n",
            "journalctl",
            "--unit=orbit-e2e-" + name + ".service",
            "--follow",
            "--lines=100",
            "--output=cat",
            "--no-pager",
        ],
        stdout=subprocess.PIPE,
        stderr=subprocess.DEVNULL,
        text=True,
    )
    try:
        assert reader.stdout is not None
        for line in reader.stdout:
            if line.rstrip("\n") == marker:
                print(marker, flush=True)
                return
        raise AssertionError("Observer journal ended before the expected event")
    finally:
        reader.terminate()
        try:
            reader.wait(timeout=2)
        except subprocess.TimeoutExpired:
            reader.kill()
            reader.wait()


def topology(action, name, *arguments):
    prefix = shlex.split(os.environ["PROOF_EXEC"])
    assert "exec" in prefix
    prefix[prefix.index("exec")] = action
    completed = subprocess.run(
        [
            *prefix,
            "app-prod",
            *([] if action == "exec" else [name]),
            *arguments,
            "--json",
        ],
        capture_output=True,
        text=True,
        check=False,
        timeout=25,
    )
    if completed.returncode != 0:
        raise AssertionError("Topology socket observation failed")
    return json.loads(completed.stdout)


def host(mode, tag, *keys):
    assert re.fullmatch(r"[a-zA-Z0-9_-]{1,128}", tag)
    state = Path(tempfile.gettempdir()) / ("orbit-proof-ws-rotate-" + tag + ".json")
    if mode == "start":
        nonce = uuid.uuid4().hex
        name = "ws-rot-" + nonce
        argv = [
            "/usr/bin/timeout",
            "--kill-after=2s",
            "120s",
            "python3",
            "-u",
            "-c",
            Path(__file__).read_text(),
            "observe",
            nonce,
            *keys,
        ]
        assert (
            topology("spawn", name, "--argv=" + json.dumps(argv))["state"] == "spawned"
        )
        state.write_text(json.dumps({"name": name, "nonce": nonce}))
    observed = json.loads(state.read_text())
    phase = "ready" if mode == "start" else "pass"
    wait_argv = [
        "/usr/bin/timeout",
        "--kill-after=2s",
        "15s",
        "python3",
        "-u",
        "-c",
        Path(__file__).read_text(),
        "wait",
        observed["nonce"],
        observed["name"],
        phase,
    ]
    failure = (
        "websocket.proof_readiness_failed"
        if mode == "start"
        else "websocket.proof_rotation_unconfirmed"
    )
    try:
        waited = topology(
            "exec", observed["name"], "--argv=" + json.dumps(wait_argv), "--timeout=20"
        )
    except (AssertionError, subprocess.TimeoutExpired):
        raise RuntimeError(failure) from None
    if waited["state"] != "executed" or waited["exit_code"] != 0:
        raise RuntimeError(failure)
    logs = topology("logs", observed["name"], "--lines=100")
    assert logs["state"] == "logs"
    marker = "WS_PROOF_READY:" if mode == "start" else "WS_PROOF_ROTATION_PASS:"
    assert marker + observed["nonce"] in logs["output"], (
        "Required socket observation is absent"
    )
    if mode == "check":
        state.unlink()
    print("F13 socket observer " + mode + ": PASS")


def main():
    mode, *arguments = sys.argv[1:]
    if mode == "observe":
        tag, *keys = arguments
        try:
            observe(*keys, tag)
        except (AssertionError, OSError, ValueError, KeyError):
            print("WS_PROOF_ROTATION_FAILED:" + tag, flush=True)
            return 1
    elif mode == "wait":
        tag, name, phase = arguments
        wait_journal(name, tag, phase)
    else:
        assert mode in ("start", "check")
        host(mode, os.environ["PROOF_RUN"], *arguments)
    return 0


if __name__ == "__main__":
    sys.exit(main())
