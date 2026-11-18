"""Run the real Gateway cutover and console recovery scripts against isolated services."""
import fcntl
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
OPERATION = "42"
HORIZON = "orbit-gateway-horizon.service"
MANAGED_HORIZON = "orbit-process-71-horizon.service"
HEADER = struct.Struct("!BBHHBB")
WORKFLOW = "swamp/workflows/workflow-gateway-release-activate.yaml"
CONTROLLER = "swamp/scripts/gateway-release-container.sh"
PROTOCOL = "# orbit-gateway-recovery-protocol: 1\n"


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

    sha, old = os.environ["FIXTURE_SHA"], os.environ["FIXTURE_OLD"]
    candidate = f"gateway-{sha[:12]}"
    if name == "runuser":
        command = args[3:]
        if command[0] == "php8.5":
            print('{"generations":[]}' if "gateway:release:cleanup" in command else (state / "receipt").read_text())
        else:
            return subprocess.call(command)
    elif name == "orbit-gateway-environment":
        assert action == "refresh"
    elif name == "orbit-gateway-site":
        (state / "site").write_text(action)
        event(f"site {action}")
        interrupt(f"after {action} switch")
    elif name == "curl":
        url = args[-1]
        if url.startswith("https:"):
            selected = (state / "site").read_text()
            host = selected == "host"
            upstream = root / "caddy/orbit-gateway-upstream.caddy"
            version = (root / "home/orbit/REVISION").read_text().strip() if host else (
                old if "10.1.0.3" in upstream.read_text() else sha
            )
            event(f"public {selected} {version}")
            if (host and (state / "broken-public").exists()) or (state / f"broken-public-{selected}-{version}").exists():
                return 22
            if url.endswith("/status"):
                interrupt(f"during {selected} public status")
                if (state / f"wrong-public-{version}").exists():
                    version = old if version == sha else sha
        else:
            version = old if "10.1.0.3" in url else sha
        if url.endswith("/status"):
            print(json.dumps({"data": {"status": "ok", "version": version}}))
    elif name == "systemctl":
        unit = args[-1]
        active = state / f"{machine}-{unit}"
        if action == "list-units" and "--state=active,activating,deactivating" in args:
            prefix = f"{machine}-"
            for service in sorted(state.glob(f"{prefix}*horizon.service")):
                print(f"{service.name.removeprefix(prefix)} loaded active running")
        elif action == "show" and "KillMode" in args:
            with (root / "home/.orbit/gateway-release.lock").open() as lock:
                try:
                    fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
                except BlockingIOError:
                    locked = True
                else:
                    locked = False
            journal = root / "var/gateway-release-switch"
            operation = journal / "operation"
            horizons = journal / "horizons"
            event(f"drain {machine} locked={locked} operation={operation.read_text().strip() if operation.exists() else '-'}"
                  f" units={','.join(horizons.read_text().splitlines()) if horizons.exists() else '-'}")
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
            if "sha256sum" in command:
                print(hashlib.sha256((state / "env").read_bytes()).hexdigest(), command[-1])
            elif command[-1].endswith("/.gateway-env-digest"):
                marker = state / (machine + "-digest")
                if "cat" in command:
                    if not marker.exists():
                        return 1
                    print(marker.read_text(), end="")
                elif "rm" in command:
                    marker.unlink(missing_ok=True)
                elif "tee" in command:
                    marker.write_bytes(sys.stdin.buffer.read())
                return 0
            elif "config:cache" in command:
                if (state / "broken-config").exists():
                    return 1
                (state / (machine + "-cache")).write_bytes((state / "env").read_bytes())
            elif command[0] == "systemctl" and command[1] == "restart" and command[-1] == "orbit-gateway-web.service":
                cached = state / (machine + "-cache")
                if cached.exists():
                    (state / (machine + "-worker")).write_bytes(cached.read_bytes())
                (state / (machine + "-orbit-gateway-web.service")).touch()
            elif command[0] in ("systemctl", "/bin/sh", "bash"):
                return subprocess.call(command, env=env)
            if "gateway:release:cleanup" in command:
                print('{"generations":[]}')
            elif "-r" in command:
                print("yes")
        elif action == "config" and args[1] == "get":
            machine = args[2]
            if args[3] == "user.orbit.release.commit":
                print(sha if machine == candidate else old)
        elif action == "config" and args[1] == "set":
            (state / f"{args[2]}-boot").write_text(args[3].split("=")[1])
        elif action == "snapshot" and args[1] == "list":
            print("[]")
        elif action == "list":
            if (state / "candidate").exists():
                print(json.dumps([{"name": candidate, "config": {
                    "user.orbit.release.operation": OPERATION, "user.orbit.release.target": "gateway",
                    "user.orbit.release.role": "candidate", "user.orbit.release.commit": sha,
                    "boot.autostart": (state / f"{candidate}-boot").read_text(),
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
        if self.server.complete:
            self.wfile.write(HEADER.pack(1, 3, 1, 8, 0, 0) + bytes(8))


class GatewayReleaseRecovery(unittest.TestCase):
    def setUp(self):
        temp = tempfile.TemporaryDirectory()
        self.addCleanup(temp.cleanup)
        self.root = Path(temp.name)
        self.home = self.root / "home"
        self.journal = self.root / "var/gateway-release-switch"
        self.inventory = self.home / ".orbit/gateway-containers.json"
        self.upstream = self.root / "caddy/orbit-gateway-upstream.caddy"
        self.state = self.root / "state"
        self.stubs = self.root / "stubs"
        self.libexec = self.root / "libexec"
        self.sock = self.root / "fpm.sock"
        for directory in (self.journal, self.inventory.parent, self.upstream.parent, self.state, self.stubs, self.libexec):
            directory.mkdir(parents=True)
        self.mirror = self.home / "shared/orbit.git"
        self.mirror.mkdir(parents=True)
        self.git("init", "--quiet", "--object-format=sha1")
        for name in ("bin/gateway-release-check", "bin/gateway-rollback", CONTROLLER, WORKFLOW,
                     "swamp/scripts/gateway-release-horizons.py", "swamp/scripts/gateway-release-host-check.py"):
            self.copy(name, self.mirror / name)
        ctl = self.mirror / "swamp/scripts/release-container.py"
        ctl.write_text('import os, sys\nif sys.argv[1] == "address": print("10.1.0.2" if sys.argv[3] == os.environ["FIXTURE_SHA"][:12] else "10.1.0.3")\n')
        self.old = self.commit("retained release")
        (self.mirror / "application-version").write_text("serving release")
        self.sha = self.commit("serving release")
        self.candidate = f"gateway-{self.sha[:12]}"
        self.retained = f"gateway-{self.old[:12]}"
        self.checkout = self.home / "releases" / self.sha[:12]
        self.checkout.mkdir(parents=True)
        (self.home / "orbit").symlink_to(self.checkout)
        (self.checkout / "REVISION").write_text(self.sha)
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
        (self.state / "env").write_text("A")
        (self.state / f"host-{HORIZON}").touch()

        self.env = {**os.environ, "PATH": f"{self.stubs}:{os.environ['PATH']}",
                    "FIXTURE_ROOT": str(self.root),
                    "FIXTURE_TEST": str(Path(__file__).resolve()),
                    "FIXTURE_SHA": self.sha, "FIXTURE_OLD": self.old}
        self.env.pop("ORBIT_GATEWAY_RELEASE_LOCK_HELD", None)
        self.env.pop("ORBIT_RELEASE_BASE", None)

        for name in ("runuser", "systemctl", "incus", "curl", "chown", "install"):
            self.make_stub(self.stubs / name)
        for name in ("orbit-gateway-site", "orbit-gateway-unit", "orbit-gateway-environment"):
            self.make_stub(self.libexec / name)

        self.move = self.copy("bin/gateway-container-move", self.root / "move")
        self.rollback = self.copy("bin/gateway-rollback", self.libexec / "orbit-gateway-rollback-host")
        self.console = self.copy("bin/gateway-container-rollback", self.root / "console")
        self.phase = self.libexec / "orbit-gateway-release-phase"
        self.install_phase()
        self.copy("swamp/scripts/gateway-release-drain.sh", self.checkout / "swamp/scripts/gateway-release-drain.sh")

    def install_phase(self, revision=None):
        # Stub preparation work only after the installed phase has checked the
        # selected recovery protocol against the real Git objects.
        prepare = """case "$phase" in
  build) echo '{}'; exit ;;
  web) cat >/dev/null; exit ;;
  clone)
    touch "$FIXTURE_ROOT/state/candidate"
    printf false > "$FIXTURE_ROOT/state/gateway-${sha:0:12}-boot"
    exit ;;
  park-preparation|wait-idle|ready) exit 0 ;;
esac
"""
        marker = "# A failed deletion must complete before a new build"
        phase = self.source("bin/gateway-release-phase", revision)
        self.phase.write_text(phase.replace(marker, prepare + marker, 1))
        self.phase.chmod(0o755)

    def install_earlier_phase(self):
        revision = os.environ.get("GATEWAY_RECOVERY_TEST_PREVIOUS_DISPATCHER")
        if revision:
            self.install_phase(revision)
        else:
            # The earlier dispatcher let the same switch code run before
            # protocol declarations were required.
            phase = self.phase.read_text()
            self.phase.write_text(phase.replace("# Manual moves", "check_recovery() { :; }\n# Manual moves", 1))


    def git(self, *args):
        return subprocess.check_output(
            ["git", "-C", self.mirror, "-c", "user.name=Fixture", "-c", "user.email=fixture@example.test", *args],
            text=True,
        ).strip()

    def commit(self, title):
        self.git("add", ".")
        self.git("commit", "--quiet", "-m", title)
        return self.git("rev-parse", "HEAD")

    def make_stub(self, path):
        path.write_text('#!/bin/sh\nexec python3 "$FIXTURE_TEST" --stub "$(basename "$0")" "$@"\n')
        path.chmod(0o755)

    def source(self, name, revision=None):
        # The base override is for reproducing the same tests against the draft.
        base = revision or os.environ.get("GATEWAY_RECOVERY_TEST_BASE")
        if base and name != "swamp/scripts/gateway-release-host-check.py":
            repo = os.environ.get("GATEWAY_RECOVERY_TEST_SOURCE_REPO", ROOT)
            source = subprocess.check_output(["git", "show", f"{base}:{name}"], cwd=repo, text=True)
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

    def run_script(self, script, *args, interrupt=""):
        env = {**self.env, "FIXTURE_INTERRUPT": interrupt}
        return subprocess.run(
            [script, *args], check=False, env=env, start_new_session=True, capture_output=True, text=True, timeout=45,
        )

    def listener(self, version=None, *, complete=True):
        server = socketserver.UnixStreamServer(str(self.sock), FastCGI)
        server.version = version or self.sha
        server.complete = complete
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
        self.assertEqual((self.journal / "candidate").read_text().strip(), self.sha)
        self.assertTrue((self.journal / f"rollback-{self.sha}").exists())

    def assert_host_serves(self):
        self.assertEqual((self.state / "site").read_text(), "host")
        self.assertTrue((self.state / f"host-{HORIZON}").exists())
        self.assertFalse((self.state / "candidate").exists())
        self.assertFalse(self.inventory.exists())
        self.assertFalse(self.upstream.exists())
        self.assertFalse((self.journal / "operation").exists())

    def assert_container_serves(self, horizon=HORIZON):
        self.assertEqual((self.state / "site").read_text(), "container")
        self.assertTrue((self.state / f"{self.candidate}-{horizon}").exists())
        self.assertFalse((self.state / f"host-{HORIZON}").exists())
        self.assertTrue((self.state / "candidate").exists())
        self.assertEqual(json.loads(self.inventory.read_text())["live"], self.sha)
        self.assertTrue(self.upstream.exists())
        self.assertFalse((self.journal / "operation").exists())

    def retained_releases(self, *incompatible):
        if incompatible:
            old_source = os.environ.get("GATEWAY_RECOVERY_TEST_OLD")
            self.git("checkout", "--quiet", "--detach", self.old)
            for name in incompatible:
                source = self.source(name, old_source) if old_source else self.source(name).replace(PROTOCOL, "")
                (self.mirror / name).write_text(source)
            self.old = self.commit("incompatible retained release")
            self.retained = f"gateway-{self.old[:12]}"
            self.env["FIXTURE_OLD"] = self.old
            self.git("checkout", "--quiet", "--detach", self.sha)
        self.assertNotEqual(self.git("rev-parse", f"{self.old}^{{tree}}"), self.git("rev-parse", f"{self.sha}^{{tree}}"))
        self.assert_ok(self.run_move())
        inventory = json.loads(self.inventory.read_text())
        self.inventory.write_text(json.dumps({**inventory, "old": self.old}))

    def assert_pending(self, sha):
        self.assertTrue((self.journal / "operation").exists(), "the switch has no recovery marker")
        self.assertEqual((self.journal / "operation").read_text().strip(), OPERATION)
        self.assertEqual((self.journal / "candidate").read_text().strip(), sha)
        self.assertTrue((self.journal / f"rollback-{sha}").exists())

    def test_reconciliation_fences_offline_rollback_until_its_own_finalizer(self):
        self.retained_releases()
        self.assert_ok(self.run_script(self.phase, "reconcile-begin", self.sha, "51"))
        original = self.inventory.read_bytes()
        result = self.run_script(self.console, self.old[:12])
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("workflows are being reconciled", result.stderr)
        self.assertNotEqual(self.run_script(self.phase, "reconcile-end", self.sha, "52").returncode, 0)
        self.assertEqual(self.inventory.read_bytes(), original)
        self.assert_ok(self.run_script(self.phase, "reconcile-verify", self.sha, "51"))
        self.assert_ok(self.run_script(self.phase, "reconcile-end", self.sha, "51"))
        self.assert_ok(self.run_script(self.console, self.old[:12]))

    def test_incompatible_retained_helper_and_workflow_are_refused_before_rollback(self):
        self.retained_releases(CONTROLLER, WORKFLOW)
        for name in (CONTROLLER, WORKFLOW):
            self.assertNotEqual(self.git("rev-parse", f"{self.sha}:{name}"), self.git("rev-parse", f"{self.old}:{name}"))
        initial = [(path, path.read_bytes()) for path in (self.inventory, self.upstream, self.state / "events")]
        for script, args in (
            (self.console, (self.old[:12],)),
            (self.phase, ("build-checked", self.old, OPERATION, "true", "{}", "true")),
            (self.phase, ("drain", self.old, OPERATION, HORIZON)),
            (self.phase, ("switch", self.old, OPERATION, self.sha[:12], HORIZON)),
        ):
            with self.subTest(args=args):
                result = self.run_script(script, *args)
                self.assertNotEqual(result.returncode, 0)
                self.assertIn("gateway.release_recovery_incompatible", result.stderr)
                for path, before in initial:
                    self.assertEqual(path.read_bytes(), before)
                self.assert_container_serves()
        self.assert_ok(self.run_script(self.phase, "release-unused", self.old, OPERATION))
        for path, before in initial:
            self.assertEqual(path.read_bytes(), before)

    def test_native_rollback_requires_compatible_activation_workflow_even_with_a_corrected_helper(self):
        self.retained_releases(WORKFLOW)
        self.assertEqual(self.git("rev-parse", f"{self.sha}:{CONTROLLER}"), self.git("rev-parse", f"{self.old}:{CONTROLLER}"))
        self.assertNotEqual(self.git("rev-parse", f"{self.sha}:{WORKFLOW}"), self.git("rev-parse", f"{self.old}:{WORKFLOW}"))
        result = self.run_script(self.phase, "build-checked", self.old, OPERATION, "true", "{}", "true")
        self.assertNotEqual(result.returncode, 0)
        self.assertIn(f"gateway.release_recovery_incompatible: {self.old} lacks container recovery protocol 1 in {WORKFLOW}", result.stderr)
        self.assert_container_serves()

    def test_native_failed_promotion_verifies_the_previous_release_before_acknowledging(self):
        self.retained_releases()
        self.assert_ok(self.run_script(self.phase, "build-checked", self.old, OPERATION, "true", "{}", "true"))
        (self.state / f"{self.candidate}-{MANAGED_HORIZON}").touch()
        self.assert_ok(self.run_script(self.phase, "switch", self.old, OPERATION, self.sha[:12], HORIZON))
        self.assertEqual(set((self.journal / "horizons").read_text().splitlines()), {HORIZON, MANAGED_HORIZON})
        (self.state / f"broken-public-container-{self.old}").touch()
        result = self.run_script(self.phase, "public-ready", self.old, OPERATION)
        self.assertNotEqual(result.returncode, 0)
        wrong = self.state / f"wrong-public-{self.sha}"
        wrong.touch()
        result = self.run_script(self.phase, "switch-back", self.old, OPERATION)
        self.assertNotEqual(result.returncode, 0)
        self.assertNotIn("gateway.release_switched_back", result.stderr)
        self.assert_pending(self.old)
        self.assertFalse((self.state / f"{self.candidate}-{MANAGED_HORIZON}").exists())
        self.assertEqual(json.loads(self.inventory.read_text())["live"], self.old)

        wrong.unlink()
        result = self.run_script(self.console, "--recover", interrupt="during container public status")
        self.assertEqual(result.returncode, -signal.SIGKILL, result.stderr)
        self.assert_pending(self.old)
        result = self.run_script(self.phase, "switch-back", self.old, OPERATION)
        self.assertEqual(result.returncode, 1, result.stderr)
        self.assertIn("gateway.release_switched_back", result.stderr)
        self.assertFalse((self.journal / "operation").exists())
        self.assertEqual(json.loads(self.inventory.read_text())["live"], self.sha)
        self.assertTrue((self.state / f"{self.candidate}-{MANAGED_HORIZON}").exists())

    def test_offline_rollback_journals_the_original_units_before_draining(self):
        self.retained_releases()
        units = [MANAGED_HORIZON, "orbit-process-73-horizon.service"]
        for unit in units:
            (self.state / f"{self.candidate}-{unit}").touch()
        unrelated = self.state / f"{self.candidate}-orbit-process-72-mailer.service"
        unrelated.touch()
        with (self.home / ".orbit/gateway-release.lock").open() as lock:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            result = self.run_script(self.console, self.old[:12])
            self.assertNotEqual(result.returncode, 0)
            self.assertIn("another Gateway release holds the lock", result.stderr)
            self.assert_container_serves()
        (self.state / f"{self.candidate}-{HORIZON}").unlink()
        result = self.run_script(self.console, self.old[:12], interrupt=f"after {self.candidate} drain")
        self.assertEqual(result.returncode, -signal.SIGKILL, result.stderr)
        self.assert_pending(self.old)
        self.assertEqual((self.journal / "horizons").read_text().splitlines(), units)
        self.assertEqual(json.loads(self.inventory.read_text())["live"], self.sha)
        events = (self.state / "events").read_text().splitlines()
        self.assertIn(f"drain {self.candidate} locked=True operation={OPERATION} units={','.join(units)}", events)
        self.assertTrue(unrelated.exists())
        self.assert_ok(self.run_script(self.console, "--recover"))
        for unit in units:
            self.assertTrue((self.state / f"{self.candidate}-{unit}").exists())
        self.assertTrue(unrelated.exists())
        self.assertFalse((self.journal / "operation").exists())

    def test_offline_failed_rollback_keeps_the_journal_when_both_endpoints_fail(self):
        self.retained_releases()
        (self.state / f"broken-public-container-{self.old}").touch()
        unavailable = self.state / f"wrong-public-{self.sha}"
        unavailable.touch()
        result = self.run_script(self.console, self.old[:12])
        self.assertNotEqual(result.returncode, 0)
        self.assertNotIn("restored previous traffic", result.stderr)
        self.assertIn("public Gateway did not verify restored release", result.stderr)
        self.assert_pending(self.old)
        self.assertFalse((self.state / f"{self.candidate}-{HORIZON}").exists())
        self.assertFalse((self.state / f"{self.retained}-{HORIZON}").exists())
        self.assertNotEqual(self.run_script(self.console, "--recover").returncode, 0)
        self.assert_pending(self.old)
        unavailable.unlink()
        self.assert_ok(self.run_script(self.console, "--recover"))
        self.assert_container_serves()

    def test_matching_earlier_container_switch_recovers_only_after_public_verification(self):
        self.retained_releases(CONTROLLER, WORKFLOW)
        (self.state / f"{self.candidate}-{MANAGED_HORIZON}").touch()
        self.install_earlier_phase()
        interrupted = self.run_script(self.phase, "switch", self.old, OPERATION, self.sha[:12], HORIZON,
                                      interrupt="after container switch")
        self.assertEqual(interrupted.returncode, -signal.SIGKILL, interrupted.stderr)
        self.assert_pending(self.old)
        self.assertFalse((self.state / f"{self.candidate}-{MANAGED_HORIZON}").exists())

        self.install_phase()
        events = self.state / "events"
        wrong = self.state / f"wrong-public-{self.sha}"
        wrong.touch()
        result = self.run_script(self.phase, "switch-back", self.old, OPERATION)
        self.assertIn(f"public Gateway did not verify restored release {self.sha}", result.stderr)
        self.assert_pending(self.old)
        self.assertFalse((self.state / f"{self.candidate}-{MANAGED_HORIZON}").exists())
        wrong.unlink()

        before = events.read_bytes()
        for phase, sha, operation in (
            ("restore", self.sha, OPERATION),
            ("switch-back", self.old, "43"),
            ("switch", self.sha, OPERATION),
        ):
            result = self.run_script(self.phase, phase, sha, operation, self.old[:12], HORIZON)
            self.assertNotEqual(result.returncode, 0)
            self.assertIn("switch", result.stderr)
            self.assert_pending(self.old)
            self.assertEqual(events.read_bytes(), before)

        result = self.run_script(self.phase, "switch-back", self.old, OPERATION,
                                 interrupt="during container public status")
        self.assertEqual(result.returncode, -signal.SIGKILL, result.stderr)
        self.assert_pending(self.old)
        self.assert_ok(self.run_script(self.console, "--recover"))
        self.assert_container_serves(horizon=MANAGED_HORIZON)
        self.assertIn(f"public container {self.sha}", events.read_text())

    def test_earlier_first_move_recovers_before_inventory_and_blocks_new_offline_rollback(self):
        previous = os.environ.get("GATEWAY_RECOVERY_TEST_PREVIOUS_DISPATCHER")
        for name in (CONTROLLER, WORKFLOW):
            text = self.source(name, previous) if previous else self.source(name).replace(PROTOCOL, "")
            (self.mirror / name).write_text(text)
        self.sha = self.commit("first move before compatibility admission")
        self.candidate = f"gateway-{self.sha[:12]}"
        self.env["FIXTURE_SHA"] = self.sha
        (self.checkout / "REVISION").write_text(self.sha)
        self.listener()
        self.install_earlier_phase()
        self.assert_interrupted(self.run_move(interrupt="after host drain"))
        self.assertFalse(self.inventory.exists())
        self.assertFalse((self.state / f"host-{HORIZON}").exists())
        events = self.state / "events"
        before = events.read_bytes()

        self.install_phase()
        result = self.run_script(self.console, "--recover", interrupt="during host public status")
        self.assertEqual(result.returncode, -signal.SIGKILL, result.stderr)
        self.assert_interrupted(result)
        self.assertFalse((self.state / f"host-{HORIZON}").exists())
        (self.home / "releases" / self.sha[:12]).symlink_to(self.checkout)
        before_retry = events.read_bytes()
        result = self.run_script(self.console, self.sha[:12])
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("--recover", result.stderr)
        self.assertEqual(events.read_bytes(), before_retry)

        self.assert_ok(self.run_script(self.console, "--recover"))
        self.assert_host_serves()
        self.assertIn(f"public host {self.sha}", events.read_text()[len(before):])

    def test_first_move_and_host_rollback_preserve_a_stopped_registered_process(self):
        receipt = json.loads((self.state / "receipt").read_text())
        (self.state / "receipt").write_text(json.dumps({**receipt, "horizon_unit": MANAGED_HORIZON}))
        (self.state / f"host-{HORIZON}").unlink()
        self.assert_ok(self.run_move())
        self.assertFalse((self.state / f"{self.candidate}-{MANAGED_HORIZON}").exists())
        self.assertFalse((self.state / f"{self.candidate}-{HORIZON}").exists())
        self.listener()
        self.assert_ok(self.run_script(self.console, "--host"))
        self.assertFalse((self.state / f"host-{MANAGED_HORIZON}").exists())
        self.assertFalse((self.state / f"host-{HORIZON}").exists())

    def test_switch_rebuilds_the_candidate_after_configuration_changes_at_readiness(self):
        self.retained_releases()
        marker = self.state / (self.retained + "-digest")
        marker.write_text(hashlib.sha256(b"A").hexdigest() + " " + str(self.home / "shared/gateway.env") + "\n")
        (self.state / (self.retained + "-worker")).write_text("A")
        (self.state / (self.retained + "-orbit-gateway-web.service")).touch()
        (self.state / "env").write_text("B")
        broken = self.state / "broken-config"
        broken.touch()
        result = self.run_script(self.phase, "switch", self.old, OPERATION, self.sha[:12], HORIZON)
        self.assertNotEqual(result.returncode, 0)
        self.assert_container_serves()
        self.assertFalse(marker.exists())
        broken.unlink()
        self.assert_ok(self.run_script(self.phase, "switch", self.old, OPERATION, self.sha[:12], HORIZON))
        self.assertEqual((self.state / (self.retained + "-worker")).read_text(), "B")

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

        self.sock.unlink()
        self.listener(complete=False)
        result = self.run_script(self.console, "--host")
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("incomplete FastCGI response", result.stderr)
        self.assertEqual((self.state / "events").read_text(), events)
        self.assert_container_serves()

    def test_host_rollback_refuses_wrong_served_commit_before_drain(self):
        self.listener(self.old)
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
        self.assert_interrupted(self.run_script(self.console, "--host", interrupt=f"before {self.candidate} drain"))
        self.assertTrue((self.state / f"{self.candidate}-{HORIZON}").exists())
        self.assert_ok(self.run_script(self.console, "--recover"))
        self.assert_container_serves()

    def test_host_rollback_recovers_after_drain_before_switch(self):
        self.listener()
        self.assert_ok(self.run_move())
        self.assert_interrupted(self.run_script(self.console, "--host", interrupt=f"after {self.candidate} drain"))
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
        unavailable = self.state / f"broken-public-container-{self.sha}"
        unavailable.touch()
        result = self.run_script(self.console, "--recover")
        self.assertNotEqual(result.returncode, 0)
        self.assert_pending(self.sha)
        self.assertTrue((self.journal / "host-fallback/inventory").exists())
        self.assertFalse((self.state / f"{self.candidate}-{HORIZON}").exists())
        unavailable.unlink()
        self.assert_ok(self.run_script(self.console, "--recover"))
        self.assert_container_serves()

    def test_host_rollback_completes_cleanup_after_verified_switch(self):
        self.listener()
        self.assert_ok(self.run_move())
        self.assert_interrupted(self.run_script(self.console, "--host", interrupt="after candidate deletion"))
        self.assertTrue((self.journal / "host-fallback/verified").exists())
        unavailable = self.state / "broken-public"
        unavailable.touch()
        result = self.run_script(self.console, "--recover")
        self.assertNotEqual(result.returncode, 0)
        self.assert_pending(self.sha)
        unavailable.unlink()
        self.assert_ok(self.run_script(self.console, "--recover"))
        self.assert_host_serves()

    def test_healthy_retained_container_rollback(self):
        self.retained_releases()
        self.assert_ok(self.run_script(self.console, self.old[:12]))
        self.assertEqual(json.loads(self.inventory.read_text())["live"], self.old)
        self.assertEqual(json.loads(self.inventory.read_text())["old"], self.sha)
        self.assertEqual((self.state / "site").read_text(), "container")
        self.assertTrue((self.state / f"gateway-{self.old[:12]}-{HORIZON}").exists())
        self.assertFalse((self.state / f"{self.candidate}-{HORIZON}").exists())

    def test_ordinary_fpm_release_and_rollback(self):
        release = self.home / "releases" / self.old[:12]
        release.mkdir()
        (release / "REVISION").write_text(self.old)
        self.copy("bin/gateway-rollback", release / "bin/gateway-rollback")
        for sha in (self.old, self.sha):
            web = self.home / "web/releases" / sha[:12]
            web.mkdir(parents=True)
            (web / "index.html").touch()

        switch = self.copy("swamp/scripts/gateway-release-switch.sh", self.root / "switch")
        result = self.run_script(switch, self.old[:12], self.old, HORIZON)
        self.assert_ok(result)
        self.assertEqual(json.loads(result.stdout), [])
        self.assertEqual((self.home / "orbit").resolve(), release)
        self.assertEqual(os.readlink(self.home / "web/current"), f"releases/{self.old[:12]}")

        self.assert_ok(self.run_script(self.console, self.sha[:12]))
        self.assertEqual((self.home / "orbit").resolve(), self.checkout)
        self.assertEqual(os.readlink(self.home / "web/current"), f"releases/{self.sha[:12]}")
        self.assertEqual((self.state / "events").read_text().count("systemctl reload php8.5-fpm"), 2)


if __name__ == "__main__":
    if sys.argv[1:2] == ["--stub"]:
        sys.exit(stub(sys.argv[2], sys.argv[3:]))
    unittest.main()
