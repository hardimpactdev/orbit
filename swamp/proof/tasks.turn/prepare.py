"""Proof-only Pi fixture. No provider, live repository or model request is used."""

import subprocess
import sys
from pathlib import Path

source = Path.cwd()
node, run_id = sys.argv[1:]


def target(*argv):
    return subprocess.check_output(
        ["incus", "exec", "target", "--", *argv], text=True
    ).strip()


def git(*argv):
    return target(
        "runuser", "-u", "orbit", "--", "git", "-C", "/home/orbit/turn-proof", *argv
    )


target("mkdir", "-p", "/home/orbit/turn-proof")
target("chown", "orbit:orbit", "/home/orbit/turn-proof")
target("ufw", "allow", "from", "10.231.0.1", "to", "any", "port", "3774", "proto", "tcp")
git("init", "-qb", "proof")
git("config", "user.name", "Proof")
git("config", "user.email", "proof@example.invalid")
target(
    "runuser",
    "-u",
    "orbit",
    "--",
    "sh",
    "-c",
    "printf 'original\\n' > /home/orbit/turn-proof/file",
)
git("add", ".")
git("commit", "-qm", "Initial")
head, tree = git("rev-parse", "HEAD"), git("write-tree")
subprocess.run(
    [
        "incus",
        "file",
        "push",
        str(source / "swamp/proof/tasks.turn/pi_fixture.py"),
        "target/tmp/turn-pi-fixture.py",
    ],
    check=True,
)
target(
    "systemd-run",
    "--unit=turn-pi-proof",
    "--uid=orbit",
    "--collect",
    "/usr/bin/python3",
    "/tmp/turn-pi-fixture.py",
)
# Probe only this fixture server's readiness; no Orbit state polling.
target(
    "timeout",
    "10",
    "python3",
    "-c",
    "import socket,time\nfor _ in range(100):\n try:\n  socket.create_connection(('127.0.0.1',3774),.1).close();break\n except OSError:time.sleep(.05)\nelse:raise SystemExit('Pi fixture not ready')",
)
result = subprocess.check_output(
    [
        "php",
        str(source / "swamp/proof/tasks.turn/prepare.php"),
        node,
        head,
        tree,
        run_id,
    ],
    cwd=source / "apps/gateway",
    text=True,
)
print(result.strip().splitlines()[-1])
