"""Run the real Gateway cutover and console recovery scripts against isolated services."""
import hashlib
import json
import os
import signal
import socket
import socketserver
import struct
import subprocess
import sys
import tempfile
import threading
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SHA = "a" * 40
OLD = "b" * 40
OPERATION = "42"
HORIZON = "orbit-gateway-horizon.service"
CANDIDATE = f"gateway-{SHA[:12]}"
HEADER = struct.Struct("!BBHHBB")


def stub(name, args):
    root = Path(os.environ["FIXTURE_ROOT"])
    action = args[0]
    machine = os.environ.get("FIXTURE_MACHINE", "host")
    state = root / "state"

    def event(value):
        with (state / "events").open("a") as out:
            print(value, file=out)

    def interrupt(at):
        if os.environ.get("FIXTURE_INTERRUPT") == at:
            os.killpg(os.getpgrp(), signal.SIGKILL)

    if name == "git":
        if "show" in args:
            path = args[-1].split(":", 1)[1]
            sys.stdout.write((root / "source" / path).read_text())
    elif name == "runuser":
        command = args[3:]
        if command[0] == "php8.5":
            print('{"generations":[]}' if "gateway:release:cleanup" in command else (state / "receipt").read_text())
        else:
            return subprocess.call(command)
    elif name == "orbit-gateway-site":
        (state / "site").write_text(action)
        event(f"site {action}")
        interrupt(f"after {action} switch")
    elif name == "curl":
        url = args[-1]
        if url.startswith("https:"):
            selected = (state / "site").read_text()
            event(f"public {selected}")
            if selected == "host" and (state / "broken-public").exists():
                return 22
        if url.endswith("/status"):
            version = os.environ["FIXTURE_SHA"] if url.startswith("https:") else OLD if "10.1.0.3" in url else SHA
            print(json.dumps({"data": {"status": "ok", "version": version}}))
    elif name == "systemctl":
        unit = args[-1]
        active = state / f"{machine}-{unit}"
        if action == "list-units" and "--state=active,activating,deactivating" in args:
            if (state / f"{machine}-{HORIZON}").exists():
                print(f"{HORIZON} loaded active running")
        elif action == "show" and "KillMode" in args:
            interrupt(f"before {machine} drain")
            print("KillMode=mixed\nSendSIGKILL=no")
        elif action == "show" and "ActiveState" in args:
            interrupt(f"after {machine} drain")
            print("active" if active.exists() else "inactive")
        elif action == "is-active":
            return 0 if active.exists() else 3
        elif action in ("start", "enable") and (action == "start" or "--now" in args):
            active.touch()
            event(f"start {machine} {unit}")
        elif action in ("stop", "disable") and (action == "stop" or "--now" in args):
            active.unlink(missing_ok=True)
            event(f"stop {machine} {unit}")
        else:
            event(f"systemctl {action} {unit}")
    elif name == "incus":
        args = args[3:]
        action = args[0]
        if action == "exec":
            machine = args[1]
            command = args[args.index("--") + 1:]
            env = {**os.environ, "FIXTURE_MACHINE": machine}
            if command[0] in ("systemctl", "/bin/sh", "bash"):
                return subprocess.call(command, env=env)
            if "gateway:release:cleanup" in command:
                print('{"generations":[]}')
            elif "-r" in command:
                print("yes")
        elif action == "config" and args[1] == "get":
            machine = args[2]
            if args[3] == "user.orbit.release.commit":
                print(SHA if machine == CANDIDATE else OLD)
        elif action == "config" and args[1] == "set":
            (state / f"{args[2]}-boot").write_text(args[3].split("=")[1])
        elif action == "snapshot" and args[1] == "list":
            print("[]")
        elif action == "list":
            if (state / "candidate").exists():
                print(json.dumps([{"name": CANDIDATE, "config": {
                    "user.orbit.release.operation": OPERATION, "user.orbit.release.target": "gateway",
                    "user.orbit.release.role": "candidate", "user.orbit.release.commit": SHA,
                    "boot.autostart": (state / f"{CANDIDATE}-boot").read_text(),
                }}]))
            else:
                print("[]")
        elif action == "delete":
            (state / "candidate").unlink()
            event("delete candidate")
            interrupt("after candidate deletion")
        else:
            raise ValueError(args)
    elif name == "install":
        return subprocess.call(["/usr/bin/install", *[arg for arg in args if arg not in ("-o", "-g", "orbit")]])
    elif name == "chown":
        pass
    return 0


class FastCGI(socketserver.StreamRequestHandler):
    def handle(self):
        request = b""
        while True:
            header = self.rfile.read(HEADER.size)
            _, kind, _, size, pad, _ = HEADER.unpack(header)
            request += self.rfile.read(size)
            self.rfile.read(pad)
            if kind == 5:
                break
        version = self.server.version
        body = (json.dumps({"data": {"status": "ok", "version": version}}).encode()
                if b"/api/v1/gateway/status" in request else b"ok")
        expected = self.server.index
        headers = b"Content-Type: application/json\r\n"
        if expected not in request:
            headers += b"Status: 404 Not Found\r\n"
        content = headers + b"\r\n" + body
        self.wfile.write(HEADER.pack(1, 6, 1, len(content), 0, 0) + content)
        self.wfile.write(HEADER.pack(1, 3, 1, 8, 0, 0) + bytes(8))


class GatewayReleaseRecovery(unittest.TestCase):
    def setUp(self):
        temp = tempfile.TemporaryDirectory()
        self.addCleanup(temp.cleanup)
        self.root = Path(temp.name)
        self.home = self.root / "home"
        self.checkout = self.home / "releases" / SHA[:12]
        self.journal = self.root / "var/gateway-release-switch"
        self.inventory = self.home / ".orbit/gateway-containers.json"
        self.upstream = self.root / "caddy/orbit-gateway-upstream.caddy"
        self.state = self.root / "state"
        self.stubs = self.root / "stubs"
        self.libexec = self.root / "libexec"
        self.sock = self.root / "fpm.sock"
        for directory in (self.checkout, self.journal, self.inventory.parent, self.upstream.parent, self.state, self.stubs, self.libexec):
            directory.mkdir(parents=True)
        (self.home / "orbit").symlink_to(self.checkout)
        (self.checkout / "REVISION").write_text(SHA)
        (self.home / ".orbit/gateway-release.lock").touch()
        self.archive = self.root / "web.zip"
        self.archive.write_bytes(b"fixture")
        digest = hashlib.sha256(self.archive.read_bytes()).hexdigest()
        (self.state / "receipt").write_text(json.dumps({
            "repository": "test/orbit", "manifest": {}, "horizon_unit": HORIZON,
            "web": {"digest": "sha256:" + digest, "artifact_id": 1, "size": 7},
        }))
        (self.state / "site").write_text("host")
        (self.state / "events").touch()
        (self.state / f"host-{HORIZON}").touch()

        self.env = {**os.environ, "PATH": f"{self.stubs}:{os.environ['PATH']}",
                    "FIXTURE_ROOT": str(self.root), "FIXTURE_SHA": SHA,
                    "FIXTURE_TEST": str(Path(__file__).resolve())}
        self.env.pop("ORBIT_GATEWAY_RELEASE_LOCK_HELD", None)
        self.env.pop("ORBIT_RELEASE_BASE", None)

        for name in ("git", "runuser", "systemctl", "incus", "curl", "chown", "install"):
            self.make_stub(self.stubs / name)
        for name in ("orbit-gateway-site", "orbit-gateway-unit"):
            self.make_stub(self.libexec / name)

        self.move = self.copy("bin/gateway-container-move", self.root / "move")
        self.rollback = self.copy("bin/gateway-rollback", self.libexec / "orbit-gateway-rollback-host")
        self.console = self.copy("bin/gateway-container-rollback", self.root / "console")
        self.copy("bin/gateway-release-phase", self.libexec / "orbit-gateway-release-phase").write_text(
            self.source("bin/gateway-release-phase").replace("shift 3", """shift 3
case "$phase" in
  build) echo '{}'; exit ;;
  web) cat >/dev/null; exit ;;
  clone)
    touch "$FIXTURE_ROOT/state/candidate"
    printf false > "$FIXTURE_ROOT/state/gateway-${sha:0:12}-boot"
    exit ;;
  park-preparation|wait-idle|ready) exit 0 ;;
esac"""))
        for name in ("bin/gateway-release-check", "bin/gateway-rollback", "swamp/scripts/gateway-release-container.sh",
                     "swamp/scripts/gateway-release-horizons.py", "swamp/scripts/gateway-release-host-check.py"):
            self.copy(name, self.root / "source" / name)
        self.copy("swamp/scripts/gateway-release-drain.sh", self.checkout / "swamp/scripts/gateway-release-drain.sh")
        ctl = self.root / "source/swamp/scripts/release-container.py"
        ctl.write_text('import sys\nif sys.argv[1] == "address": print("10.1.0.2" if sys.argv[3] == "aaaaaaaaaaaa" else "10.1.0.3")\n')

    def make_stub(self, path):
        path.write_text('#!/bin/sh\nexec python3 "$FIXTURE_TEST" --stub "$(basename "$0")" "$@"\n')
        path.chmod(0o755)

    def source(self, name):
        # The base override is for reproducing the same tests against the draft.
        base = os.environ.get("GATEWAY_RECOVERY_TEST_BASE")
        if base and name != "swamp/scripts/gateway-release-host-check.py":
            source = subprocess.check_output(["git", "show", f"{base}:{name}"], cwd=ROOT, text=True)
        else:
            source = (ROOT / name).read_text()
        for original, fixture in {
            "/home/orbit": self.home, "/var/lib/orbit": self.root / "var", "/etc/caddy": self.root / "caddy",
            "/usr/local/libexec": self.libexec, "/run/php/orbit-gateway.sock": self.sock,
        }.items():
            source = source.replace(original, str(fixture))
        return source.replace("$EUID", "0").replace("os.geteuid() != 0", "False").replace("info.st_uid != 0", "info.st_uid != os.getuid()")

    def copy(self, name, path):
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(self.source(name))
        path.chmod(0o755)
        return path

    def run_script(self, script, *args, interrupt="", sha=SHA):
        env = {**self.env, "FIXTURE_INTERRUPT": interrupt, "FIXTURE_SHA": sha}
        return subprocess.run(
            [script, *args], check=False, env=env, start_new_session=True, capture_output=True, text=True, timeout=45,
        )

    def listener(self, version=SHA):
        server = socketserver.UnixStreamServer(str(self.sock), FastCGI)
        server.version = version
        server.index = os.fsencode(self.checkout / "apps/gateway/public/index.php")
        thread = threading.Thread(target=server.serve_forever, kwargs={"poll_interval": 0.01})
        thread.start()
        self.addCleanup(server.server_close)
        self.addCleanup(thread.join)
        self.addCleanup(server.shutdown)

    def run_move(self, interrupt=""):
        return self.run_script(self.move, OPERATION, self.archive, interrupt=interrupt)

    def assert_ok(self, result):
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)

    def assert_interrupted(self, result):
        self.assertEqual(result.returncode, -signal.SIGKILL, result.stdout + result.stderr)
        self.assertEqual((self.journal / "operation").read_text().strip(), OPERATION)
        self.assertEqual((self.journal / "candidate").read_text().strip(), SHA)
        self.assertTrue((self.journal / f"rollback-{SHA}").exists())

    def assert_host_serves(self):
        self.assertEqual((self.state / "site").read_text(), "host")
        self.assertTrue((self.state / f"host-{HORIZON}").exists())
        self.assertFalse((self.state / "candidate").exists())
        self.assertFalse(self.inventory.exists())
        self.assertFalse(self.upstream.exists())
        self.assertFalse((self.journal / "operation").exists())

    def assert_container_serves(self):
        self.assertEqual((self.state / "site").read_text(), "container")
        self.assertTrue((self.state / f"{CANDIDATE}-{HORIZON}").exists())
        self.assertFalse((self.state / f"host-{HORIZON}").exists())
        self.assertTrue((self.state / "candidate").exists())
        self.assertEqual(json.loads(self.inventory.read_text())["live"], SHA)
        self.assertTrue(self.upstream.exists())
        self.assertFalse((self.journal / "operation").exists())

    def test_first_move_recovers_before_drain(self):
        self.listener()
        self.assert_interrupted(self.run_move("before host drain"))
        self.assertTrue((self.state / f"host-{HORIZON}").exists())
        self.assertFalse(self.inventory.exists())
        self.assert_ok(self.run_script(self.console, "--recover"))
        self.assert_host_serves()

    def test_first_move_recovers_after_drain_before_switch(self):
        self.listener()
        self.assert_interrupted(self.run_move("after host drain"))
        self.assertFalse((self.state / f"host-{HORIZON}").exists())
        self.assertFalse(self.inventory.exists())
        self.assert_ok(self.run_script(self.console, "--recover"))
        self.assert_host_serves()

    def test_first_move_recovers_after_caddy_switch(self):
        self.listener()
        self.assert_interrupted(self.run_move("after container switch"))
        self.assertEqual((self.state / "site").read_text(), "container")
        self.assertTrue(self.inventory.exists())
        self.assert_ok(self.run_script(self.console, "--recover"))
        self.assert_host_serves()

    def test_host_rollback_refuses_broken_listener_before_drain(self):
        self.assert_ok(self.run_move())
        events = (self.state / "events").read_text()
        with socket.socket(socket.AF_UNIX) as unavailable:
            unavailable.bind(str(self.sock))
            result = self.run_script(self.console, "--host")
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("Gateway host is not ready", result.stderr)
        self.assertEqual((self.state / "events").read_text(), events)
        self.assert_container_serves()

    def test_host_rollback_refuses_wrong_served_commit_before_drain(self):
        self.listener(OLD)
        self.assert_ok(self.run_move())
        events = (self.state / "events").read_text()
        result = self.run_script(self.console, "--host")
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("host PHP-FPM did not report the selected release", result.stderr)
        self.assertEqual((self.state / "events").read_text(), events)
        self.assert_container_serves()

    def test_host_rollback_restores_container_on_public_failure(self):
        self.listener()
        self.assert_ok(self.run_move())
        (self.state / "broken-public").touch()
        result = self.run_script(self.console, "--host")
        self.assertNotEqual(result.returncode, 0)
        self.assert_container_serves()

    def test_host_rollback_recovers_before_drain(self):
        self.listener()
        self.assert_ok(self.run_move())
        self.assert_interrupted(self.run_script(self.console, "--host", interrupt=f"before {CANDIDATE} drain"))
        self.assertTrue((self.state / f"{CANDIDATE}-{HORIZON}").exists())
        self.assert_ok(self.run_script(self.console, "--recover"))
        self.assert_container_serves()

    def test_host_rollback_recovers_after_drain_before_switch(self):
        self.listener()
        self.assert_ok(self.run_move())
        self.assert_interrupted(self.run_script(self.console, "--host", interrupt=f"after {CANDIDATE} drain"))
        self.assertEqual((self.state / "site").read_text(), "container")
        self.assertTrue(self.inventory.exists())
        self.assert_ok(self.run_script(self.console, "--recover"))
        self.assert_container_serves()

    def test_host_rollback_recovers_after_switch_before_verification(self):
        self.listener()
        self.assert_ok(self.run_move())
        self.assert_interrupted(self.run_script(self.console, "--host", interrupt="after host switch"))
        self.assertEqual((self.state / "site").read_text(), "host")
        self.assertTrue(self.inventory.exists())
        self.assert_ok(self.run_script(self.console, "--recover"))
        self.assert_container_serves()

    def test_host_rollback_completes_cleanup_after_verified_switch(self):
        self.listener()
        self.assert_ok(self.run_move())
        self.assert_interrupted(self.run_script(self.console, "--host", interrupt="after candidate deletion"))
        self.assertTrue((self.journal / "host-fallback/verified").exists())
        self.assert_ok(self.run_script(self.console, "--recover"))
        self.assert_host_serves()

    def test_healthy_retained_container_rollback(self):
        self.assert_ok(self.run_move())
        inventory = json.loads(self.inventory.read_text())
        self.inventory.write_text(json.dumps({**inventory, "old": OLD}))
        self.assert_ok(self.run_script(self.console, OLD[:12], sha=OLD))
        self.assertEqual(json.loads(self.inventory.read_text())["live"], OLD)
        self.assertEqual(json.loads(self.inventory.read_text())["old"], SHA)
        self.assertEqual((self.state / "site").read_text(), "container")
        self.assertTrue((self.state / f"gateway-{OLD[:12]}-{HORIZON}").exists())
        self.assertFalse((self.state / f"{CANDIDATE}-{HORIZON}").exists())

    def test_ordinary_fpm_release_and_rollback(self):
        release = self.home / "releases" / OLD[:12]
        release.mkdir()
        (release / "REVISION").write_text(OLD)
        self.copy("bin/gateway-rollback", release / "bin/gateway-rollback")
        for sha in (OLD, SHA):
            web = self.home / "web/releases" / sha[:12]
            web.mkdir(parents=True)
            (web / "index.html").touch()

        switch = self.copy("swamp/scripts/gateway-release-switch.sh", self.root / "switch")
        result = self.run_script(switch, OLD[:12], OLD, HORIZON)
        self.assert_ok(result)
        self.assertEqual(json.loads(result.stdout), [])
        self.assertEqual((self.home / "orbit").resolve(), release)
        self.assertEqual(os.readlink(self.home / "web/current"), f"releases/{OLD[:12]}")

        self.assert_ok(self.run_script(self.console, SHA[:12]))
        self.assertEqual((self.home / "orbit").resolve(), self.checkout)
        self.assertEqual(os.readlink(self.home / "web/current"), f"releases/{SHA[:12]}")
        self.assertEqual((self.state / "events").read_text().count("systemctl reload php8.5-fpm"), 2)


if __name__ == "__main__":
    if sys.argv[1:2] == ["--stub"]:
        sys.exit(stub(sys.argv[2], sys.argv[3:]))
    unittest.main()
