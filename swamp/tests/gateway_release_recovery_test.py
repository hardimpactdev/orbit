"""Run the real Gateway cutover and console recovery scripts against isolated services."""
import fcntl
import hashlib
import json
import os
import signal
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
OPERATION = "42"
HORIZON = "orbit-gateway-horizon.service"
MANAGED_HORIZON = "orbit-process-71-horizon.service"
WORKFLOW = "swamp/workflows/workflow-production-release-activate.yaml"
CONTROLLER = "swamp/scripts/gateway-release-container.sh"
PROTOCOL = "# orbit-gateway-recovery-protocol: 2\n"


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
            if machine == candidate and unit == HORIZON and (state / "broken-horizon").exists():
                return 1
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
            if any(arg.endswith("/bin/gateway-horizon-ready") for arg in command):
                event("Horizon readiness " + machine)
                return 1 if (state / "broken-heartbeat").exists() else 0
            if "operations:check-cleanup" in command:
                if "--fence-only" in command:
                    event("fence " + machine)
                    return 1 if (state / "broken-fence").exists() else 0
                print('{"status":"checked"}')
                result = state / "cleanup-exit"
                return int(result.read_text()) if result.exists() else 0
            if "gateway:release:cleanup" in command:
                print('{"generations":[]}')
            elif "-r" in command:
                print("no" if (state / "unconfigured-queue").exists() else "yes")
        elif action == "config" and args[1] == "get":
            machine = args[2]
            if args[3] == "user.orbit.release.commit":
                print(sha if machine == candidate else old)
            elif args[3] == "user.orbit.release.operation":
                print(OPERATION)
        elif action == "config" and args[1] == "set":
            (state / f"{args[2]}-boot").write_text(args[3].split("=")[1])
        elif action == "snapshot" and args[1] == "list":
            print("[]")
        elif action == "list":
            rows = []
            for key, target, commit in (("candidate", candidate, sha), ("former", "gateway-" + old[:12], old)):
                if (state / key).exists():
                    boot = state / f"{target}-boot"
                    rows.append({"name": target, "status": (state / key).read_text().strip() or "Running", "config": {
                        "user.orbit.release.operation": OPERATION, "user.orbit.release.target": "gateway",
                        "user.orbit.release.role": "candidate", "user.orbit.release.commit": commit,
                        "boot.autostart": boot.read_text().strip() if boot.exists() else "false",
                    }})
            if (state / "orphan").exists():
                rows.append({"name": "gateway-" + "c"*12, "status": "Stopped", "config": {"user.orbit.release.target":"gateway"}})
            print(json.dumps(rows))
        elif action in ("delete", "stop"):
            target = args[1]
            key = "candidate" if target == candidate else "former"
            if action == "delete":
                (state / key).unlink()
                event("delete " + key)
                interrupt("after " + key + " deletion")
            else:
                (state / key).write_text("Stopped")
                event("stop " + key)
        else:
            raise ValueError(args)
    elif name == "install":
        return subprocess.call(["/usr/bin/install", *[arg for arg in args if arg not in ("-o", "-g", "orbit")]])
    elif name == "chown":
        pass
    return 0


class GatewayReleaseFixture(unittest.TestCase):
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
        marker = "# Cleanup belongs to the current host controller"
        phase = self.source("bin/gateway-release-phase", revision)
        self.phase.write_text(phase.replace(marker, prepare + marker, 1))
        self.phase.chmod(0o755)

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
            "/usr/local/libexec": self.libexec,
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

    def run_move(self, interrupt=""):
        return self.run_script(self.move, OPERATION, self.archive, interrupt=interrupt)

    def assert_ok(self, result):
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)

    def assert_interrupted(self, result):
        self.assertEqual(result.returncode, -signal.SIGKILL, result.stdout + result.stderr)
        self.assertEqual((self.journal / "operation").read_text().strip(), OPERATION)
        self.assertEqual((self.journal / "candidate").read_text().strip(), self.sha)


    def assert_container_serves(self, horizon=HORIZON):
        self.assertEqual((self.state / "site").read_text(), "container")
        self.assertTrue((self.state / f"{self.candidate}-{horizon}").exists())
        self.assertFalse((self.state / f"host-{HORIZON}").exists())
        self.assertTrue((self.state / "candidate").exists())
        self.assertEqual(json.loads(self.inventory.read_text())["live"], self.sha)
        self.assertTrue(self.upstream.exists())
        self.assertFalse((self.journal / "operation").exists())

    def serving_former(self):
        (self.state / f"host-{HORIZON}").unlink(missing_ok=True)
        self.inventory.write_text(json.dumps({"version": 2, "live": self.old, "old": None, "host_retired": True}))
        self.upstream.write_text("vars gateway_release_upstream 10.1.0.3:8080\n")
        (self.state / "site").write_text("container")
        (self.state / "former").touch()
        (self.state / "candidate").touch()
        (self.state / f"{self.retained}-boot").write_text("true")
        for service in (HORIZON, "orbit-gateway-web.service"):
            (self.state / f"{self.retained}-{service}").touch()

    def switch(self, **kwargs):
        return self.run_script(self.phase, "switch", self.sha, OPERATION, self.old[:12], HORIZON, **kwargs)

    def direct(self, action, *args):
        return self.run_script(self.mirror / CONTROLLER, action, self.sha, OPERATION, *args)

    def assert_pending(self, sha):
        self.assertTrue((self.journal / "operation").exists(), "the switch has no recovery marker")
        self.assertEqual((self.journal / "operation").read_text().strip(), OPERATION)
        self.assertEqual((self.journal / "candidate").read_text().strip(), sha)



class GatewayReleaseRecovery(GatewayReleaseFixture):
    def test_first_move_publishes_before_draining_host_horizon(self):
        self.assert_ok(self.run_move())
        events = (self.state / "events").read_text()
        self.assertLess(events.index("site container"), events.index("drain host"))
        self.assertLess(events.index("start " + self.candidate + " " + HORIZON), events.index("drain host"))
        self.assert_container_serves()
        self.assertEqual(json.loads(self.inventory.read_text()), {"version":2, "host_retired":True, "old":None, "live":self.sha})

    def test_interruption_after_caddy_publication_republishes_only_the_candidate(self):
        self.assert_interrupted(self.run_move("after container switch"))
        self.assertFalse(self.inventory.exists())
        self.assert_ok(self.run_script(self.console, "--recover"))
        self.assert_container_serves()
        self.assertNotIn("site host", (self.state / "events").read_text())

    def test_precommit_candidate_configuration_failure_keeps_the_previous_live(self):
        self.serving_former()
        (self.state / "broken-config").touch()
        self.assertNotEqual(self.switch().returncode, 0)
        self.assertFalse((self.journal / "operation").exists())
        self.assertEqual(json.loads(self.inventory.read_text())["live"], self.old)
        self.assertTrue((self.state / f"{self.retained}-{HORIZON}").exists())
        self.assertFalse((self.state / f"{self.candidate}-{HORIZON}").exists())

    def test_interrupted_drain_finishes_forward_with_a_failing_public_check(self):
        self.serving_former()
        self.assert_ok(self.switch())
        (self.state / f"broken-public-container-{self.sha}").touch()
        self.assertNotEqual(self.run_script(self.phase, "public-ready", self.sha, OPERATION).returncode, 0)
        self.assert_interrupted(self.run_script(self.phase, "retire", self.sha, OPERATION, interrupt=f"before {self.retained} drain"))
        self.assertTrue((self.state / "former").exists())
        self.assert_ok(self.run_script(self.console, "--recover"))
        self.assertFalse((self.state / "former").exists())
        self.assert_container_serves()

    def test_crash_after_former_deletion_finishes_without_recreating_it(self):
        self.serving_former()
        self.assert_ok(self.switch())
        self.assert_interrupted(self.run_script(self.phase, "retire", self.sha, OPERATION, interrupt="after former deletion"))
        self.assertFalse((self.state / "former").exists())
        self.assert_ok(self.run_script(self.console, "--recover"))
        self.assert_container_serves()
        self.assertEqual((self.state / "events").read_text().count("delete former"), 1)

    def test_configuration_refresh_and_background_desired_state_survive_the_switch(self):
        self.serving_former()
        (self.state / "env").write_text("B")
        self.assert_ok(self.switch())
        self.assertEqual((self.state / (self.candidate + "-worker")).read_text(), "B")
        self.assertTrue((self.state / f"{self.retained}-{HORIZON}").exists())
        self.assert_ok(self.run_script(self.phase, "start-horizon", self.sha, OPERATION, HORIZON))
        self.assert_ok(self.run_script(self.phase, "retire", self.sha, OPERATION))
        self.assertFalse((self.state / f"{self.retained}-{HORIZON}").exists())
        self.assertFalse((self.state / "former").exists())

    def test_recovery_keeps_former_consumers_when_the_new_horizon_fails(self):
        self.serving_former()
        self.assert_ok(self.switch())
        failed = self.state / "broken-horizon"
        failed.touch()
        self.assertNotEqual(self.run_script(self.console, "--recover").returncode, 0)
        self.assert_pending(self.sha)
        self.assertTrue((self.state / f"{self.retained}-{HORIZON}").exists())
        self.assertTrue((self.state / "former").exists())
        failed.unlink()
        unconfigured = self.state / "unconfigured-queue"
        unconfigured.touch()
        result = self.run_script(self.console, "--recover")
        self.assertIn("former fallback Horizon was active", result.stderr)
        self.assert_pending(self.sha)
        self.assertTrue((self.state / f"{self.retained}-{HORIZON}").exists())
        unconfigured.unlink()
        heartbeat = self.state / "broken-heartbeat"
        heartbeat.touch()
        self.assertNotEqual(self.run_script(self.console, "--recover").returncode, 0)
        self.assertTrue((self.state / f"{self.candidate}-{HORIZON}").exists())
        self.assertTrue((self.state / f"{self.retained}-{HORIZON}").exists())
        self.assert_pending(self.sha)
        heartbeat.unlink()
        self.assert_ok(self.run_script(self.console, "--recover"))
        self.assert_container_serves()
        self.assertFalse((self.state / "former").exists())

    def test_admission_fence_failure_preserves_the_current_route_and_a_recoverable_target(self):
        self.serving_former()
        failed = self.state / "broken-fence"
        failed.touch()
        self.assertNotEqual(self.switch().returncode, 0)
        self.assert_pending(self.sha)
        self.assertEqual(json.loads(self.inventory.read_text())["live"], self.old)
        self.assertTrue((self.state / f"{self.retained}-{HORIZON}").exists())
        failed.unlink()
        self.assert_ok(self.switch())
        events = (self.state / "events").read_text()
        self.assertLess(events.index("fence " + self.candidate), events.index("site container"))
        self.assert_ok(self.run_script(self.console, "--recover"))
        self.assertEqual((self.state / "events").read_text().count("fence " + self.candidate), 3)

    def test_cleanup_reports_deferred_and_propagates_other_failures(self):
        self.assert_ok(self.run_move())
        code = self.state / "cleanup-exit"
        code.write_text("75")
        result = self.run_script(self.phase, "cleanup-versions", self.sha, OPERATION)
        self.assert_ok(result)
        self.assertEqual(json.loads(result.stdout), {"status":"checked"})
        code.write_text("31")
        self.assertEqual(self.run_script(self.phase, "cleanup-versions", self.sha, OPERATION).returncode, 31)


if __name__ == "__main__":
    if sys.argv[1:2] == ["--stub"]:
        sys.exit(stub(sys.argv[2], sys.argv[3:]))
    unittest.main()
