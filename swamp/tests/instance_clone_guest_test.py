"""Exercise clone files at a private filesystem boundary, without Incus or root."""

import contextlib
import importlib.util
import io
import json
import os
import runpy
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import MagicMock, patch

SPEC = importlib.util.spec_from_file_location(
    "clone_guest", Path(__file__).parents[1] / "scripts/instance_clone_guest.py"
)
GUEST = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(GUEST)


NO_MERGE = {"own": [], "drop": [], "guard": []}

# The patched boundary below replaces subprocess.run; real Git still runs through this.
REAL_RUN = subprocess.run
REAL_WALK = os.walk


@contextlib.contextmanager
def checkout_mark(instance_id=9):
    """The root-owned mark of this Instance's first checkout, at its container path under a temporary root."""
    with tempfile.TemporaryDirectory() as folder:
        base = Path(folder)
        mark = base / GUEST.CHECKOUT_MARK.lstrip("/")
        mark.parent.mkdir(parents=True)
        mark.write_text(json.dumps({"instance_id": instance_id, "commit": "abc123"}) + "\n")
        with patch.object(GUEST.pathlib, "Path", lambda p: base / str(p).lstrip("/")):
            yield mark

# The default's .env of the seed copy, with hand-set keys .env.example lacks. Every value is a fixture.
DEFAULT_ENV = """APP_NAME=Lindaretel
APP_KEY=base64:default-key
APP_URL=https://default.lindaretel.test
VITE_APP_URL="${APP_URL}"
QUEUE_CONNECTION=redis

# Hand-set service keys.
S3_BUCKET=default-bucket
S3_SECRET="hand set secret"
DB_CONNECTION=mysql
DB_HOST=10.0.0.5
DB_DATABASE=lindaretel_default
DB_USERNAME=lindaretel
DB_PASSWORD=default-password-1
DATABASE_URL=mysql://lindaretel:default-password-1@10.0.0.5/lindaretel_default
REPORTS_DB=lindaretel_default
REDIS_USERNAME=default-user
REDIS_PASSWORD=default-redis-secret
REDIS_PREFIX=default_
"""

# The Gateway's render for the clone: the template plus the clone's own keys.
CLONE_RENDER = """APP_NAME="Laravel"
APP_KEY="base64:template-key"
APP_URL="https://feature.lindaretel.test"
QUEUE_CONNECTION="database"
DB_CONNECTION="mysql"
DB_HOST="10.0.0.5"
DB_DATABASE="orbit_instance_9"
DB_USERNAME="orbit_instance_9"
DB_PASSWORD="clone-password-9"
REDIS_USERNAME="orbit_instance_9"
REDIS_PASSWORD="clone-redis-9"
REDIS_PREFIX="orbit_instance_9_database_"
TEMPLATE_ONLY="from-example"
"""

CLONE_MERGE = {
    "own": ["APP_URL", "DB_CONNECTION", "DB_DATABASE", "DB_HOST", "DB_PASSWORD", "DB_PORT", "DB_USERNAME",
            "REDIS_PASSWORD", "REDIS_PREFIX", "REDIS_USERNAME"],
    "drop": ["DB_CONNECTION", "DB_DATABASE", "DB_HOST", "DB_PASSWORD", "DB_PORT", "DB_USERNAME",
             "REDIS_CONNECTION", "REDIS_DATABASE", "REDIS_HOST", "REDIS_PASSWORD", "REDIS_PORT", "REDIS_USERNAME"],
    "guard": ["DB_DATABASE", "DB_PASSWORD", "DB_USERNAME", "REDIS_DATABASE", "REDIS_PASSWORD", "REDIS_USERNAME"],
}


class CloneEnvironmentMergeTest(unittest.TestCase):
    def parsed(self, text):
        return {k: v for k, _, v in GUEST.env_blocks(text) if k is not None}

    def test_a_clone_keeps_its_defaults_hand_set_keys_and_owns_its_connections(self):
        merged, copied, dropped = GUEST.merge_environment(DEFAULT_ENV, CLONE_RENDER, CLONE_MERGE)
        values = self.parsed(merged)
        # The default's own settings, as written, including APP_KEY and a reference.
        self.assertEqual(values["S3_BUCKET"], "default-bucket")
        self.assertEqual(values["S3_SECRET"], "hand set secret")
        self.assertEqual(values["QUEUE_CONNECTION"], "redis")
        self.assertEqual(values["APP_NAME"], "Lindaretel")
        self.assertEqual(values["APP_KEY"], "base64:default-key")
        self.assertIn('VITE_APP_URL="${APP_URL}"\n', merged)
        self.assertIn("# Hand-set service keys.\n", merged)
        # The clone's own database, Valkey user, key space and Route.
        self.assertEqual(values["APP_URL"], "https://feature.lindaretel.test")
        self.assertEqual({k: values[k] for k in ("DB_DATABASE", "DB_USERNAME", "DB_PASSWORD")},
                         {"DB_DATABASE": "orbit_instance_9", "DB_USERNAME": "orbit_instance_9", "DB_PASSWORD": "clone-password-9"})
        self.assertEqual({k: values[k] for k in ("REDIS_USERNAME", "REDIS_PASSWORD", "REDIS_PREFIX")},
                         {"REDIS_USERNAME": "orbit_instance_9", "REDIS_PASSWORD": "clone-redis-9", "REDIS_PREFIX": "orbit_instance_9_database_"})
        # A key the default has only through a credential is never copied under another name.
        self.assertNotIn("DATABASE_URL", values)
        self.assertNotIn("REPORTS_DB", values)
        for secret in ("default-password-1", "lindaretel_default", "default-redis-secret", "default-user"):
            self.assertNotIn(secret, merged)
        # A key of the template that the default lacks is added.
        self.assertEqual(values["TEMPLATE_ONLY"], "from-example")
        self.assertEqual(copied, ["APP_KEY", "APP_NAME", "QUEUE_CONNECTION", "S3_BUCKET", "S3_SECRET", "VITE_APP_URL"])
        self.assertEqual(dropped, ["DATABASE_URL", "REPORTS_DB"])
        self.assertEqual(len([k for k, _, _ in GUEST.env_blocks(merged) if k == "DB_PASSWORD"]), 1)

    def test_a_dropped_key_of_the_default_goes_even_when_the_clone_does_not_own_it(self):
        merged, _, dropped = GUEST.merge_environment("REDIS_HOST=10.0.0.9\nREDIS_DATABASE=3\nKEEP=1\n", "KEEP=2\n",
                                                     {"own": [], "drop": ["REDIS_HOST", "REDIS_DATABASE"], "guard": []})
        self.assertEqual(merged, "KEEP=1\n")
        self.assertEqual(dropped, ["REDIS_DATABASE", "REDIS_HOST"])

    def test_without_a_seed_env_the_render_is_the_file_byte_for_byte(self):
        for base in ("", "\n"):
            self.assertEqual(GUEST.merge_environment(base, CLONE_RENDER, CLONE_MERGE), (CLONE_RENDER, [], []))

    def test_without_merge_names_the_render_is_the_file_byte_for_byte(self):
        # A create that a Gateway admitted before the merge names existed, run by this script.
        self.assertEqual(GUEST.merge_environment(DEFAULT_ENV, CLONE_RENDER, None), (CLONE_RENDER, [], []))

    def test_a_default_without_hand_set_keys_gives_the_render_values(self):
        render = 'APP_NAME="Tool"\nAPP_KEY="base64:k"\nDB_DATABASE="orbit_instance_9"\n'
        base = 'APP_NAME="Tool"\nAPP_KEY="base64:k"\nDB_DATABASE="tool_default"\n'
        merged, _, _ = GUEST.merge_environment(base, render, {"own": ["DB_DATABASE"], "drop": ["DB_DATABASE"], "guard": ["DB_DATABASE"]})
        self.assertEqual(merged, render)

    def test_an_escaped_backslash_before_the_closing_quote_hides_no_later_key(self):
        base = 'WIN="C:\\\\"\nDB_PASSWORD=default-password-1\nDB_ALIAS=default-password-1\n'
        merged, copied, dropped = GUEST.merge_environment(base, 'DB_PASSWORD="clone-password-9"\n', CLONE_MERGE)
        self.assertEqual(self.parsed(merged), {"WIN": "C:\\", "DB_PASSWORD": "clone-password-9"})
        self.assertNotIn("default-password-1", merged)
        self.assertEqual((copied, dropped), (["WIN"], ["DB_ALIAS"]))

    def test_a_key_name_phpdotenv_reads_is_a_key_and_a_comment_keeps_no_credential(self):
        base = "APP.ALIAS=default-password-1\n1ALIAS=default-password-1\n# DB_PASSWORD=default-password-1\n# plain note\nDB_PASSWORD=default-password-1\n"
        merged, _, dropped = GUEST.merge_environment(base, 'DB_PASSWORD="clone-password-9"\n', CLONE_MERGE)
        self.assertNotIn("default-password-1", merged)
        self.assertEqual(merged, '# plain note\nDB_PASSWORD="clone-password-9"\n')
        self.assertEqual(dropped, ["1ALIAS", "APP.ALIAS"])

    def test_an_unclosed_quote_takes_only_its_own_line(self):
        base = 'BROKEN="never closed\nDB_PASSWORD=default-password-1\n'
        merged, _, _ = GUEST.merge_environment(base, 'DB_PASSWORD="clone-password-9"\n', CLONE_MERGE)
        self.assertNotIn("default-password-1", merged)
        self.assertIn('DB_PASSWORD="clone-password-9"\n', merged)

    def test_a_multi_line_quoted_value_stays_whole(self):
        base = 'CERT="line one\nline two"\nB=2\n'
        merged, copied, _ = GUEST.merge_environment(base, "", NO_MERGE)
        self.assertEqual(merged, base)
        self.assertEqual(copied, ["B", "CERT"])


HOSTS = {"from": ["horizon-new-dawn.test", "old.horizon-new-dawn.test"], "to": "walk-q.horizon-new-dawn-website.test"}


class CloneDefaultHostRewriteTest(unittest.TestCase):
    def key(self, line):
        return GUEST.env_blocks(line)[0][0]

    def rewrite(self, line):
        return GUEST.rewrite_default_hosts(line, {self.key(line)}, HOSTS)

    def test_a_value_that_points_at_the_defaults_own_site_points_at_the_clones(self):
        cases = {
            # The live defect: the default's literal URL.
            'VITE_APP_URL="https://horizon-new-dawn.test"\n': 'VITE_APP_URL="https://walk-q.horizon-new-dawn-website.test"\n',
            "A=https://horizon-new-dawn.test/build/?v=1\n": "A=https://walk-q.horizon-new-dawn-website.test/build/?v=1\n",
            "A=HTTP://Horizon-New-Dawn.test\n": "A=HTTP://walk-q.horizon-new-dawn-website.test\n",
            "A=horizon-new-dawn.test\n": "A=walk-q.horizon-new-dawn-website.test\n",
            "A='horizon-new-dawn.test:5173'\n": "A='walk-q.horizon-new-dawn-website.test:5173'\n",
            "A=wss://horizon-new-dawn.test:8080/app\n": "A=wss://walk-q.horizon-new-dawn-website.test:8080/app\n",
            "A=//horizon-new-dawn.test\n": "A=//walk-q.horizon-new-dawn-website.test\n",
            "SESSION_DOMAIN=.horizon-new-dawn.test\n": "SESSION_DOMAIN=.walk-q.horizon-new-dawn-website.test\n",
            "A=*.horizon-new-dawn.test\n": "A=*.walk-q.horizon-new-dawn-website.test\n",
            # Every Route of the default counts.
            "A=old.horizon-new-dawn.test\n": "A=walk-q.horizon-new-dawn-website.test\n",
            # A list keeps its other items and its separators.
            'SANCTUM_STATEFUL_DOMAINS="localhost,horizon-new-dawn.test:443, 127.0.0.1"\n':
                'SANCTUM_STATEFUL_DOMAINS="localhost,walk-q.horizon-new-dawn-website.test:443, 127.0.0.1"\n',
            "CORS=https://app.example.com;https://horizon-new-dawn.test\n": "CORS=https://app.example.com;https://walk-q.horizon-new-dawn-website.test\n",
            "A=horizon-new-dawn.test # the default's host\n": "A=walk-q.horizon-new-dawn-website.test # the default's host\n",
            "export A = horizon-new-dawn.test\n": "export A = walk-q.horizon-new-dawn-website.test\n",
        }
        for line, expected in cases.items():
            with self.subTest(line=line):
                self.assertEqual(self.rewrite(line), (expected, [self.key(line)]))

    def test_a_value_that_only_contains_the_defaults_host_stays(self):
        for line in (
            # A reference already follows APP_URL.
            'VITE_APP_URL="${APP_URL}"\n',
            "A=${APP_URL}/api\n",
            # Third-party URLs and names that contain the same text.
            "A=https://horizon-new-dawn.test.cdn.example.com\n",
            "A=https://cdn.example.com/horizon-new-dawn.test/app.js\n",
            "A=https://login.example.com/?return=https://horizon-new-dawn.test\n",
            "A=https://login.example.com/?sites=a,horizon-new-dawn.test\n",
            "A=https://x.example.com/#horizon-new-dawn.test\n",
            "A=my-horizon-new-dawn.test\n",
            "A=sub.horizon-new-dawn.test\n",
            "A=horizon-new-dawn.testing\n",
            "A=user@horizon-new-dawn.test\n",
            "A=https://user@horizon-new-dawn.test\n",
            'A="https://example.com/\\"horizon-new-dawn.test"\n',
            # Another host, the clone's own host and a comment line.
            "A=https://horizon-new-dawn-website.test\n",
            "A=https://walk-q.horizon-new-dawn-website.test\n",
            "# A=https://horizon-new-dawn.test\n",
        ):
            with self.subTest(line=line):
                self.assertEqual(self.rewrite(line), (line, []))

    def test_only_copied_keys_change_and_names_are_all_that_is_reported(self):
        text = "APP_URL=https://horizon-new-dawn.test\nVITE_APP_URL=https://horizon-new-dawn.test\nB=2\n"
        self.assertEqual(GUEST.rewrite_default_hosts(text, {"VITE_APP_URL", "B"}, HOSTS),
                         ("APP_URL=https://horizon-new-dawn.test\nVITE_APP_URL=https://walk-q.horizon-new-dawn-website.test\nB=2\n",
                          ["VITE_APP_URL"]))

    def test_a_multi_line_value_and_crlf_lines_keep_their_form(self):
        text = 'CERT="horizon-new-dawn.test\nline two"\r\nA=horizon-new-dawn.test\r\n'
        self.assertEqual(GUEST.rewrite_default_hosts(text, {"CERT", "A"}, HOSTS),
                         ('CERT="walk-q.horizon-new-dawn-website.test\nline two"\r\nA=walk-q.horizon-new-dawn-website.test\r\n',
                          ["A", "CERT"]))

    def test_without_a_clone_host_or_a_default_host_nothing_changes(self):
        line = "A=https://horizon-new-dawn.test\n"
        for hosts in ({"from": ["horizon-new-dawn.test"], "to": None}, {"from": [], "to": "x.test"}):
            with self.subTest(hosts=hosts):
                self.assertEqual(GUEST.rewrite_default_hosts(line, {"A"}, hosts), (line, []))


class WorkspaceCheckoutTest(unittest.TestCase):
    """The real workspace and sessions phases on a real Git workspace; only root, systemd, T3 and the Node helper are faked."""

    def setUp(self):
        folder = tempfile.TemporaryDirectory()
        self.addCleanup(folder.cleanup)
        self.base = Path(folder.name)
        self.origin = self.base / "origin"
        self.workspace = self.base / "srv/workspace"
        self.git(self.base, "init", "--quiet", "--initial-branch=main", str(self.origin))
        self.commit(self.origin, "seed")
        self.git(self.origin, "switch", "--quiet", "-c", "proof")
        self.commit(self.origin, "older")
        self.older = self.head(self.origin)
        self.commit(self.origin, "fetched")
        self.expected = self.head(self.origin)
        self.git(self.base, "clone", "--quiet", str(self.origin), str(self.workspace))

    def git(self, cwd, *args):
        return subprocess.run(["git", "-c", "user.name=Fixture", "-c", "user.email=fixture@example.test", *args], cwd=cwd,
                              check=True, capture_output=True, text=True, timeout=10,
                              env=dict(os.environ, GIT_CONFIG_NOSYSTEM="1", GIT_CONFIG_GLOBAL="/dev/null"))

    def commit(self, cwd, message):
        (Path(cwd) / "file").write_text(message)
        self.git(cwd, "add", "file")
        self.git(cwd, "commit", "--quiet", "-m", message)

    def head(self, cwd):
        return self.git(cwd, "rev-parse", "HEAD").stdout.strip()

    def branch(self):
        return self.git(self.workspace, "branch", "--show-current").stdout.strip()

    def seed(self, branch, commit):
        """The seed copy: the default's workspace, on a branch of the default."""
        self.git(self.workspace, "checkout", "--quiet", "-B", branch, commit)

    def phase(self, phase, container=None, private=None, commands=None, walk=False):
        container = container or {"instance_id": 9, "workspace": "/srv/workspace", "branch": "proof", "expected_commit": self.expected,
                     "bundle": "/tmp/proof.bundle", "php": None, "environment_file": "/srv/workspace/.env",
                     "units": [], "processes": [], "clone": {"t3": {"internal_port": 3773, "t3": "/opt/agent/bin/t3"}}}
        if phase in {"workspace", "checkout"}:
            # The Node pushes the mirror's branch in as a bundle before each workspace phase.
            (self.base / "tmp").mkdir(exist_ok=True)
            self.git(self.origin, "bundle", "create", "--quiet", str(self.base / "tmp/proof.bundle"), "refs/heads/proof")

        def command(argv, **_kwargs):
            actual = argv[4:] if argv[0] == "runuser" else argv
            if commands is not None and actual[0] != "git":
                commands.append(actual)
            if actual[0] == "git" and actual[-3:] == ["config", "--global", "user.name"]:
                # The agent part of the T3 step wrote the agent's Git identity.
                return subprocess.CompletedProcess(argv, 0, "Orbit Agent\n", "")
            if actual[0] == "git":
                actual = [str(self.base / "tmp/proof.bundle") if a == container["bundle"] else a for a in actual]
                return REAL_RUN(actual, cwd=self.workspace, check=False, capture_output=True, text=True, timeout=10,
                                      env=dict(os.environ, GIT_CONFIG_NOSYSTEM="1", GIT_CONFIG_GLOBAL="/dev/null"))
            stdout = ""
            if "/usr/local/libexec/orbit-instance-node-runtime.py" in actual:
                stdout = '{"status":"unchanged"}'
            elif actual[0] == "curl":
                stdout = '{"environmentId":"clone-env","serverVersion":"0.1.7"}'
            elif actual[:2] in (["systemctl", "is-enabled"], ["systemctl", "is-active"]) and commands is not None:
                # A fresh copy: no unit of the clone is enabled or running yet.
                return subprocess.CompletedProcess(argv, 3, "", "")
            return subprocess.CompletedProcess(argv, 0, stdout, "")

        def walked(top):
            # The real rights walk over the fixture, reported at the container's paths.
            for directory, ds, fs in REAL_WALK(self.base / top.lstrip("/")):
                yield "/" + str(Path(directory).relative_to(self.base)), ds, fs

        connection = MagicMock()
        connection.getresponse.side_effect = lambda: type("Reply", (io.BytesIO,), {"status": 200})(
            json.dumps({"authenticated": True, "scopes": ["access:read", "access:write"]}).encode())
        if private is None:
            private = {"environment": "", "merge": NO_MERGE} if phase in {"workspace", "checkout"} else {
                key: {"token": "private-" + key} for key in ["gateway", "swamp"]}
        output = io.StringIO()
        with (
            patch.object(GUEST.pathlib, "Path", lambda p: self.base / str(p).lstrip("/")),
            patch.object(GUEST.subprocess, "run", command),
            patch.object(GUEST.os, "walk", walked if walk else lambda _top: []),
            patch.object(GUEST.os, "chown"),
            patch.object(GUEST.http.client, "HTTPConnection", return_value=connection),
            patch.object(GUEST.sys, "stdin", io.StringIO(json.dumps(private))),
            contextlib.redirect_stdout(output),
        ):
            GUEST.phase_main(phase, json.dumps(container))
        return json.loads(output.getvalue())

    def mark(self):
        mark = self.base / GUEST.CHECKOUT_MARK.lstrip("/")
        return json.loads(mark.read_text()), mark.stat().st_mode & 0o777

    def test_a_first_run_on_another_branch_checks_out_the_fetched_commit_and_marks_it(self):
        self.seed("main", self.older)
        result = self.phase("workspace")
        self.assertIn("checkout", result["changes"])
        self.assertEqual(result["commit"], self.expected)
        self.assertEqual((self.branch(), self.head(self.workspace)), ("proof", self.expected))
        self.assertEqual(self.mark(), ({"instance_id": 9, "commit": self.expected}, 0o600))
        self.assertFalse((self.base / "tmp/proof.bundle").exists())

    def test_a_first_run_on_a_seed_branch_of_the_same_name_still_checks_out_the_fetched_commit(self):
        # The default's own branch has the target's name but an older head; a mark of another Instance does not count.
        self.seed("proof", self.older)
        mark = self.base / GUEST.CHECKOUT_MARK.lstrip("/")
        mark.parent.mkdir(parents=True)
        mark.write_text(json.dumps({"instance_id": 1, "commit": self.older}) + "\n")
        result = self.phase("workspace")
        self.assertIn("checkout", result["changes"])
        self.assertEqual(result["commit"], self.expected)
        self.assertEqual((self.branch(), self.head(self.workspace)), ("proof", self.expected))
        self.assertEqual(self.mark()[0], {"instance_id": 9, "commit": self.expected})

    def test_a_retry_never_moves_the_branch_this_instance_checked_out(self):
        self.seed("main", self.older)
        self.phase("workspace")
        # The user commits on the branch; then a late failure and a retry run the same phases again.
        self.commit(self.workspace, "user work")
        work = self.head(self.workspace)
        result = self.phase("workspace")
        self.assertNotIn("checkout", result["changes"])
        self.assertEqual((self.branch(), self.head(self.workspace)), ("proof", work))
        self.assertEqual(self.git(self.workspace, "log", "--format=%s", "-1").stdout.strip(), "user work")
        # The result names the commit the workspace started at, not the user's.
        self.assertEqual(result["commit"], self.expected)
        self.assertEqual(self.phase("sessions")["phase"], "sessions")

    def test_sessions_refuse_a_workspace_without_this_instances_mark(self):
        self.seed("proof", self.expected)
        with self.assertRaises(FileNotFoundError):
            self.phase("sessions")
        mark = self.base / GUEST.CHECKOUT_MARK.lstrip("/")
        mark.parent.mkdir(parents=True)
        mark.write_text(json.dumps({"instance_id": 1, "commit": self.expected}) + "\n")
        with self.assertRaises(AssertionError):
            self.phase("sessions")


    # The workspace's two parts. A container with PHP, a web config, a unit and a Process with its own environment file.
    def full_container(self):
        return {"instance_id": 9, "workspace": "/srv/workspace", "branch": "proof", "expected_commit": self.expected,
                "bundle": "/tmp/proof.bundle", "php": "/opt/runtime/php/8.5/bin/php", "environment_file": "/srv/workspace/.env",
                "web_config": {"path": "/etc/frankenphp/Caddyfile", "content": "site\n"},
                "units": [{"name": "orbit-frankenphp.service", "content": "[Service]\n"}],
                "processes": [{"name": "orbit-process-19.service", "id": 19, "content": "[Service]\n",
                               "environment": "/etc/orbit/processes/19/abc.env"}],
                "clone": {"t3": {"internal_port": 3773, "t3": "/opt/agent/bin/t3"}}}

    def full_private(self):
        return {"environment": CLONE_RENDER, "processes": {"/etc/orbit/processes/19/abc.env": "QUEUE=fixture\n"},
                "merge": CLONE_MERGE}

    def files(self):
        """Every file the phases leave outside Git's own folder, by container path; the mark's commit is this fixture's."""
        found = {}
        for path in sorted(self.base.rglob("*")):
            relative = path.relative_to(self.base)
            if relative.parts[0] == "origin" or ".git" in relative.parts or not path.is_file():
                continue
            text = path.read_text().replace(self.expected, "<commit>")
            found["/" + str(relative)] = (text, path.stat().st_mode & 0o777)
        return found

    def state(self):
        """What a run leaves, free of this fixture's own commit id: each fresh fixture commits anew, and a new second gives a new id."""
        mark, mode = self.mark()
        named = dict(mark, commit=mark["commit"] == self.expected)
        return self.files(), (named, mode), self.branch(), self.head(self.workspace) == self.expected

    def named(self, commands):
        """The commands of a run with this fixture's own commit id named, so two fixtures compare."""
        return json.loads(json.dumps(commands).replace(self.expected, "<commit>"))

    def changed(self, result):
        """The changes a phase names, at container paths: the fixture's root maps container paths to its own folder."""
        return [change.replace(str(self.base), "") for change in result["changes"]]

    def fresh(self):
        """A new fixture with the same seed: the default's `.env` on an older commit of `main`."""
        self.setUp()
        self.seed("main", self.older)
        (self.workspace / ".env").write_text(DEFAULT_ENV)

    def test_checkout_then_services_equal_the_one_workspace_phase(self):
        self.fresh()
        whole_commands = []
        whole = self.phase("workspace", self.full_container(), self.full_private(), whole_commands)
        whole["changes"] = self.changed(whole)
        whole_state = self.state()
        whole_commands, whole_commit = self.named(whole_commands), self.expected

        self.fresh()
        part_commands = []
        first = self.phase("checkout", self.full_container(), self.full_private(), part_commands)
        second = self.phase("services", self.full_container(), self.full_private(), part_commands)
        second["changes"] = self.changed(second)
        self.assertEqual(self.state(), whole_state)
        # The same units start, the same Node store call runs, in the same order.
        self.assertEqual(self.named(part_commands), whole_commands)
        self.assertIn(["systemctl", "start", "orbit-process-19.service"], whole_commands)
        self.assertIn(["systemctl", "start", "orbit-frankenphp.service"], whole_commands)
        # One printed result as before: the same keys, the same changes, the commit and the environment names from the checkout.
        self.assertEqual(set(whole), {"phase", "changes", "environment", "commit"})
        self.assertEqual((whole["phase"], whole["commit"], whole["environment"]), ("workspace", whole_commit, first["environment"]))
        # The checkout's changes come first, then the rest; the PHP settings now follow `.env` (nothing reads the order).
        self.assertEqual(whole["changes"], first["changes"] + second["changes"])
        self.assertEqual((first["phase"], first["commit"]), ("checkout", self.expected))
        self.assertIn("checkout", first["changes"])
        self.assertIn("/srv/workspace/.env", first["changes"])
        self.assertEqual(second, {"phase": "services", "changes": second["changes"]})
        self.assertIn("start:orbit-process-19.service", second["changes"])

    def test_checkout_starts_no_unit_and_writes_nothing_outside_the_workspace(self):
        self.fresh()
        commands = []
        self.phase("checkout", self.full_container(), self.full_private(), commands)
        self.assertEqual(commands, [])
        self.assertEqual({path for path in self.files() if not path.startswith("/srv/workspace/")}, {GUEST.CHECKOUT_MARK})

    def test_services_leave_git_status_unchanged(self):
        self.fresh()
        script = self.workspace / "run.sh"
        script.write_text("#!/bin/sh\n")
        script.chmod(0o755)
        (self.workspace / "plain").write_text("plain\n")
        (self.workspace / "plain").chmod(0o644)
        self.git(self.workspace, "add", "run.sh", "plain")
        self.git(self.workspace, "commit", "--quiet", "-m", "user files")
        self.expected = self.head(self.workspace)
        self.git(self.workspace, "update-ref", "refs/heads/proof", self.expected)
        self.git(self.workspace, "checkout", "--quiet", "proof")
        self.phase("checkout", self.full_container(), self.full_private())
        before = self.git(self.workspace, "status", "--porcelain", "--untracked-files=all").stdout
        self.phase("services", self.full_container(), self.full_private(), [], walk=True)
        self.assertEqual(self.git(self.workspace, "status", "--porcelain", "--untracked-files=all").stdout, before)
        # The rights walk ran: it adds group bits and never an exec bit Git tracks.
        self.assertEqual(script.stat().st_mode & 0o777, 0o775)
        self.assertEqual((self.workspace / "plain").stat().st_mode & 0o777, 0o664)

    def test_a_retry_of_the_checkout_never_moves_the_branch_this_instance_checked_out(self):
        self.fresh()
        self.phase("checkout", self.full_container(), self.full_private())
        self.commit(self.workspace, "user work")
        work = self.head(self.workspace)
        result = self.phase("checkout", self.full_container(), self.full_private())
        self.assertNotIn("checkout", result["changes"])
        self.assertEqual((self.branch(), self.head(self.workspace), result["commit"]), ("proof", work, self.expected))

    def test_services_refuse_a_workspace_this_instance_did_not_check_out(self):
        self.fresh()
        commands = []
        with self.assertRaises(FileNotFoundError):
            self.phase("services", self.full_container(), self.full_private(), commands)
        # A seed copy keeps its default's mark until this Instance checks out.
        mark = self.base / GUEST.CHECKOUT_MARK.lstrip("/")
        mark.parent.mkdir(parents=True)
        mark.write_text(json.dumps({"instance_id": 1, "commit": self.older}) + "\n")
        with self.assertRaises(AssertionError):
            self.phase("services", self.full_container(), self.full_private(), commands)
        self.assertEqual(commands, [])

    def test_check_passes_after_the_users_own_commit_and_refuses_without_the_mark(self):
        self.fresh()
        self.phase("workspace", self.full_container(), self.full_private())
        # The user works on the branch while a later check runs: the check reads the mark, not the commit.
        self.commit(self.workspace, "user work")
        container = dict(self.full_container(), php=None, units=[], processes=[])
        self.assertEqual(self.phase("check", container, ""), {"phase": "check", "changes": []})
        (self.base / GUEST.CHECKOUT_MARK.lstrip("/")).write_text(json.dumps({"instance_id": 1}) + "\n")
        with self.assertRaises(AssertionError):
            self.phase("check", container, "")


class CloneGuestTest(unittest.TestCase):
    def test_sessions_check_only_loopback_t3_and_reject_http_and_scope_failures(self):
        container = {
            "workspace": "/srv/workspace",
            "branch": "feature",
            "expected_commit": "abc",
            "instance_id": 9,
            "php": None,
            "clone": {"t3": {"internal_port": 3773}},
            "units": [],
            "processes": [],
        }
        existing = {
            "gateway": {"token": "private-gateway"},
            "swamp": {"token": "private-swamp"},
        }

        def command(argv, **_kwargs):
            if "curl" in argv:
                value = '{"environmentId":"clone-env","serverVersion":"0.1.7"}'
            elif "symbolic-ref" in argv:
                value = "feature"
            elif "rev-parse" in argv:
                value = "abc"
            else:
                value = ""
            return subprocess.CompletedProcess(argv, 0, value, "")

        for status, scopes, error in [
            (200, ["access:read", "access:write"], None),
            (401, ["access:read", "access:write"], RuntimeError),
            (200, ["access:read"], AssertionError),
        ]:
            with self.subTest(status=status, scopes=scopes):
                connection = MagicMock()

                def response(status=status, scopes=scopes):
                    body = io.BytesIO(
                        json.dumps({"authenticated": True, "scopes": scopes}).encode()
                    )
                    body.status = status
                    return body

                connection.getresponse.side_effect = response
                output = io.StringIO()
                with (
                    patch.object(
                        GUEST.http.client, "HTTPConnection", return_value=connection
                    ) as connect,
                    patch.object(GUEST.subprocess, "run", command),
                    patch.object(GUEST.sys, "stdin", io.StringIO(json.dumps(existing))),
                    checkout_mark(),
                    contextlib.redirect_stdout(output),
                ):
                    if error:
                        with self.assertRaises(error):
                            GUEST.phase_main("sessions", json.dumps(container))
                        self.assertEqual(output.getvalue(), "")
                    else:
                        GUEST.phase_main("sessions", json.dumps(container))
                        reply = json.loads(output.getvalue())
                        self.assertEqual(reply["changes"], [])
                        self.assertEqual(
                            reply["private_reply"]["gateway"]["token"],
                            "private-gateway",
                        )
                        self.assertEqual(connection.request.call_count, 2)
                    connect.assert_called_with("127.0.0.1", 3773, timeout=5)
                    connection.request.assert_any_call(
                        "GET",
                        "/api/auth/session",
                        headers={"Authorization": "Bearer private-gateway"},
                    )
                    self.assertEqual(connection.request.call_count, 1 if error else 2)
                    self.assertEqual(connection.close.call_count, 1 if error else 2)

    def test_workspace_forwards_one_json_vault_value_without_printing_secrets(self):
        for merged in (True, False):
            with self.subTest(merge=merged):
                self.forward_workspace(merged)

    def forward_workspace(self, merged):
        payload = {
            "environment": "KEY=private\n--orbit-run-1--\n",
            "processes": {"/etc/orbit/processes/1/env": "QUEUE=private\n"},
            "merge": {"own": ["DB_DATABASE"], "drop": ["DB_PASSWORD"], "guard": ["DB_PASSWORD"]},
        }
        if not merged:
            # A value framed by a Gateway before the merge names existed.
            del payload["merge"]
        calls = []
        elapsed = [0.0]
        timeouts = []

        def command(argv, **kwargs):
            calls.append((argv, kwargs.get("input")))
            timeouts.append(kwargs["timeout"])
            duration = 101 if "exec" in argv else 2.5
            if duration >= kwargs["timeout"]:
                raise subprocess.TimeoutExpired(argv, kwargs["timeout"])
            elapsed[0] += duration
            # The guest names its first checkout's commit; the mirror's branch head ("abc") may be newer on a retry.
            value = '{"changes":[],"commit":"first-checkout"}' if "exec" in argv else "abc"
            return subprocess.CompletedProcess(argv, 0, value, "")

        with tempfile.TemporaryDirectory() as folder:
            container = {
                "name": "instance-8",
                "project": "fixture",
                "instance_id": 8,
                "mirror": folder,
                "branch": "feature",
            }
            output = io.StringIO()
            with (
                patch.object(
                    sys,
                    "argv",
                    [
                        "clone.py",
                        json.dumps(container),
                        "guest-source",
                        "network-check-source",
                        "orbit",
                        folder,
                        json.dumps({"node": "node-helper-source"}),
                        "workspace",
                    ],
                ),
                patch.object(sys, "stdin", io.StringIO(json.dumps(payload))),
                patch.object(subprocess, "run", command),
                patch.object(GUEST.time, "monotonic", side_effect=lambda: elapsed[0]),
                contextlib.redirect_stdout(output),
            ):
                runpy.run_path(
                    str(Path(__file__).parents[1] / "scripts/instance_clone.py")
                )
            delivered = [
                json.loads(content) for argv, content in calls if "exec" in argv
            ]
            self.assertEqual(delivered, [payload if merged else {**payload, "merge": None}])
            self.assertEqual(timeouts[-1], 105)
            self.assertEqual(elapsed[0], 111)

            installed = [(argv, content) for argv, content in calls if "file" in argv and "push" in argv and argv[-1].endswith("/usr/local/libexec/orbit-instance-node-runtime.py")]
            self.assertEqual(len(installed), 1)
            self.assertEqual(installed[0][1], "node-helper-source")
            self.assertIn("/usr/local/libexec/orbit-instance-node-runtime.py", installed[0][0][-1])
            self.assertLess(calls.index(installed[0]), next(i for i, (argv, _) in enumerate(calls) if "exec" in argv))

            self.assertNotIn("private", output.getvalue())
            # The result's commit is the guest's word, not the mirror's head.
            self.assertTrue(output.getvalue().startswith("orbit-commit=first-checkout\n"))
            self.assertNotIn('"commit"', output.getvalue())

    def readiness(self, phase, php=None, fail=None, entrypoint=False, existing=False, inactive=False, state=None, desired="running", is_active=1, expected_exit=1,
                  branch="proof", mark=None):
        container = {
            "workspace": "/srv/workspace",
            "branch": "proof",
            "expected_commit": "abc123",
            "instance_id": 9,
            "php": php,
            "units": [],
            "processes": [{"name": "orbit-process-vp-dev.service", "id": 19, "desired": desired}] if inactive else [],
            "clone": {
                "seed_commit": "abc123",
                "t3": {"internal_port": 3773, "t3": "/opt/agent/bin/t3"},
            },
        }
        commands = []

        def command(argv, **_kwargs):
            # The fake boundary rejects PHP exactly as a container without PHP does.
            actual = argv[4:] if argv[0] == "runuser" else argv
            commands.append(actual)
            code, stdout = 0, ""
            if actual[0] == "php":
                code = 1 if php is None else 0
            if actual[0] == fail:
                code = 17
            if actual[0] == "curl":
                stdout = json.dumps(
                    {"environmentId": "proof", "serverVersion": "0.1.7"}
                )
            if actual[:2] == ['systemctl', 'is-active'] and inactive:
                code = is_active
            if actual[:2] == ['systemctl', 'show']:
                observed = state if state is not None else {'ActiveState': 'failed', 'SubState': 'failed', 'Result': 'exit-code', 'ExecMainStatus': '1', 'NRestarts': '5'}
                stdout = '\n'.join(key + '=' + str(value) for key, value in observed.items())
            if actual[0] == "git":
                stdout = branch + "\n" if "symbolic-ref" in actual else "abc123\n"
            if actual[0] == "/opt/agent/bin/t3":
                stdout = actual[actual.index("--label") + 1] + "-PRIVATE-TOKEN\n"
            return subprocess.CompletedProcess(argv, code, stdout, "token=ghp_SUPER_PRIVATE_TOKEN_12345678901234567890")

        sessions = {
            key: {"token": key + "-PRIVATE-TOKEN"} for key in ["gateway", "swamp"]
        }
        response = io.BytesIO(
            json.dumps(
                {"authenticated": True, "scopes": ["access:read", "access:write"]}
            ).encode()
        )
        response.status = 200

        def opened():
            response.seek(0)
            return response

        connection = MagicMock()
        connection.getresponse.side_effect = opened

        output, error = io.StringIO(), io.StringIO()
        with (
            patch.object(GUEST.subprocess, "run", command),
            patch.object(
                GUEST.sys,
                "stdin",
                io.StringIO(json.dumps(sessions) if existing else ""),
            ),
            patch.object(GUEST.http.client, "HTTPConnection", return_value=connection),
            checkout_mark((mark or {}).get("instance_id", 9)),
            contextlib.redirect_stdout(output),
            contextlib.redirect_stderr(error),
        ):
            if entrypoint:
                with patch("sys.argv", [str(SPEC.origin), phase, json.dumps(container)]):
                    if expected_exit:
                        with self.assertRaises(SystemExit) as exit_status:
                            runpy.run_path(SPEC.origin, run_name="__main__")
                        self.assertEqual(exit_status.exception.code, expected_exit)
                    else:
                        runpy.run_path(SPEC.origin, run_name="__main__")
            else:
                GUEST.phase_main(phase, json.dumps(container))
        self.assertNotIn("PRIVATE", error.getvalue())
        return commands, output.getvalue(), error.getvalue()

    def test_sessions_never_check_units_but_keep_the_branch_and_mark_checks(self):
        # The Processes start in the completion: a dead or absent unit no longer fails the create's sessions check.
        commands, output, error = self.readiness('sessions', entrypoint=True, inactive=True, expected_exit=0)
        self.assertEqual((json.loads(output)['phase'], error), ('sessions', ''))
        self.assertNotIn('process_failure', output)
        self.assertFalse(any(argv[:1] == ['systemctl'] for argv in commands))
        self.assertTrue(any('symbolic-ref' in argv for argv in commands))
        # Another branch, or a mark that names another Instance, still fails it.
        _, output, error = self.readiness('sessions', entrypoint=True, branch='other')
        self.assertEqual((output, error), ('', 'instance.container_sessions_failed\n'))
        _, output, error = self.readiness('sessions', entrypoint=True, mark={'instance_id': 8, 'commit': 'abc123'})
        self.assertEqual((output, error), ('', 'instance.container_sessions_failed\n'))

    def test_the_check_names_unit_states_of_a_dead_process_and_exits_without_private_log(self):
        commands, output, error = self.readiness('check', entrypoint=True, inactive=True)
        receipt = json.loads(output)
        self.assertEqual(receipt, {'error_code': 'instance.container_process_inactive', 'process_failure': {
            'unit': 'orbit-process-vp-dev.service', 'process_id': 19, 'ActiveState': 'failed',
            'SubState': 'failed', 'Result': 'exit-code', 'ExecMainStatus': 1, 'NRestarts': 5}})
        self.assertEqual(error, 'instance.container_process_inactive\n')
        self.assertNotIn('PRIVATE', output + error)
        self.assertFalse(any(argv[0] == 'journalctl' for argv in commands))

        healthy = {'ActiveState': 'active', 'SubState': 'running', 'Result': 'success', 'ExecMainStatus': '0', 'NRestarts': '5'}
        for label, options in [
            ('healthy', {'is_active': 0}),
            ('recovered between reads', {'state': healthy}),
            ('desired stopped', {'desired': 'stopped'}),
        ]:
            with self.subTest(label=label):
                calls, output, error = self.readiness('check', entrypoint=True, inactive=True,
                                                     expected_exit=0, **options)
                self.assertEqual(json.loads(output)['phase'], 'check')
                self.assertNotIn('process_failure', output)
                self.assertEqual(error, '')
                if label == 'desired stopped':
                    self.assertFalse(any(argv[0] == 'systemctl' for argv in calls))

        stopped = {'ActiveState': 'inactive', 'SubState': 'dead', 'Result': 'success',
                   'ExecMainStatus': '0', 'NRestarts': '0'}
        _, output, _ = self.readiness('check', entrypoint=True, inactive=True, state=stopped)
        self.assertEqual(json.loads(output)['process_failure']['ActiveState'], 'inactive')
        self.assertEqual(json.loads(output)['process_failure']['ExecMainStatus'], 0)

        transitional = {'ActiveState': 'activating', 'SubState': 'auto-restart', 'Result': 'exit-code',
                        'ExecMainStatus': '1', 'NRestarts': '5'}
        _, output, _ = self.readiness('check', entrypoint=True, inactive=True, state=transitional)
        reported = json.loads(output)['process_failure']
        self.assertEqual(reported['ActiveState'], 'activating')
        self.assertEqual(reported['SubState'], 'auto-restart')
        self.assertEqual(reported['Result'], 'exit-code')

        private = {key: 'PRIVATE_TOKEN_ghp_12345678901234567890' for key in ('ActiveState', 'SubState', 'Result')}
        private.update(ExecMainStatus='-1', NRestarts='999999999999999999999')
        _, output, _ = self.readiness('check', entrypoint=True, inactive=True, state=private)
        reported = json.loads(output)['process_failure']
        self.assertEqual({key: reported[key] for key in ('ActiveState', 'SubState', 'Result')},
                         dict.fromkeys(('ActiveState', 'SubState', 'Result'), 'unknown'))
        self.assertIsNone(reported['ExecMainStatus'])
        self.assertIsNone(reported['NRestarts'])
        self.assertNotIn('PRIVATE', output)

    def test_check_passes_a_good_clone_and_refuses_a_dead_unit_with_the_sessions_code(self):
        _, output, error = self.readiness('check', entrypoint=True, inactive=True, is_active=0, expected_exit=0)
        self.assertEqual((json.loads(output), error), ({'phase': 'check', 'changes': []}, ''))
        _, output, error = self.readiness('check', entrypoint=True, inactive=True)
        self.assertEqual(json.loads(output)['error_code'], 'instance.container_process_inactive')
        self.assertEqual(json.loads(output)['process_failure']['unit'], 'orbit-process-vp-dev.service')
        self.assertEqual(error, 'instance.container_process_inactive\n')

    def test_non_php_sessions_keep_both_authenticated_sessions_without_php(self):
        for existing in [False, True]:
            with self.subTest(existing=existing):
                commands, output, _ = self.readiness("sessions", existing=existing)
                result = json.loads(output)
                self.assertEqual(result["phase"], "sessions")
                self.assertEqual(result["private_reply"]["gateway"]["status"], 200)
                self.assertEqual(result["private_reply"]["swamp"]["status"], 200)
                self.assertEqual(
                    result["changes"], [] if existing else ["sessions-issued"]
                )
                self.assertFalse(any(argv[0] == "php" for argv in commands))

    def test_non_php_check_has_no_php_probe(self):
        commands, _, _ = self.readiness("check")
        self.assertFalse(any(argv[0] == "php" for argv in commands))

    def test_php_readiness_still_checks_the_project_runtime(self):
        for phase in ["sessions", "check"]:
            with self.subTest(phase=phase):
                commands, _, _ = self.readiness(
                    phase, php="/opt/runtime/php/8.5/bin/php"
                )
                self.assertIn(["php", "-v"], commands)

    def test_command_failure_names_command_and_exit_code_without_private_output(self):
        _, output, error = self.readiness("sessions", fail="curl", entrypoint=True)
        self.assertEqual(output, "")
        self.assertEqual(
            error, "instance.container_sessions_failed: command curl exited 17\n"
        )

    def test_broken_php_still_fails_readiness(self):
        _, output, error = self.readiness(
            "sessions",
            php="/opt/runtime/php/8.5/bin/php",
            fail="php",
            entrypoint=True,
        )
        self.assertEqual(output, "")
        self.assertEqual(
            error, "instance.container_sessions_failed: command php exited 17\n"
        )

    def test_non_php_workspace_does_not_write_php_configuration(self):
        with tempfile.TemporaryDirectory() as folder:
            base = Path(folder)
            (base / "srv/workspace/.git").mkdir(parents=True)
            env = base / "srv/workspace/.env"
            env.write_text("PRIVATE=env\n")
            container = {
                "workspace": "/srv/workspace",
                "branch": "proof",
                "expected_commit": "abc123",
                "instance_id": 9,
                "bundle": "/tmp/proof.bundle",
                "php": None,
                "environment_file": "/srv/workspace/.env",
                "units": [],
                "processes": [],
            }

            helper_timeouts = []

            def command(argv, **_kwargs):
                if "/usr/local/libexec/orbit-instance-node-runtime.py" in argv:
                    helper_timeouts.append(_kwargs["timeout"])
                    return subprocess.CompletedProcess(argv, 0, '{"status":"unchanged"}', "")
                value = "proof\n" if "symbolic-ref" in argv else "abc123\n"
                return subprocess.CompletedProcess(argv, 0, value, "")

            output = io.StringIO()
            with (
                patch.object(
                    GUEST.pathlib, "Path", lambda p: base / str(p).lstrip("/")
                ),
                patch.object(GUEST.subprocess, "run", command),
                patch.object(GUEST.os, "walk", return_value=[]),
                patch.object(
                    GUEST.sys,
                    "stdin",
                    io.StringIO(json.dumps({"environment": "PRIVATE=env\n", "merge": NO_MERGE})),
                ),
                contextlib.redirect_stdout(output),
            ):
                GUEST.phase_main("workspace", json.dumps(container))
            self.assertEqual(json.loads(output.getvalue())["changes"], [])
            # The phase prints the names of the keys it kept from the default's file, never a value.
            self.assertEqual(json.loads(output.getvalue())["environment"], {"copied": ["PRIVATE"], "dropped": []})
            self.assertNotIn("env\\n", output.getvalue())
            self.assertFalse((base / "etc/orbit/php").exists())
            self.assertEqual(helper_timeouts, [75])

    def run_workspace(self, base_env, inputs):
        """The workspace phase on a seed file; returns the written file and the printed environment names."""
        with tempfile.TemporaryDirectory() as folder:
            base = Path(folder)
            (base / "srv/workspace/.git").mkdir(parents=True)
            env = base / "srv/workspace/.env"
            env.write_text(base_env)
            container = {
                "workspace": "/srv/workspace",
                "branch": "proof",
                "expected_commit": "abc123",
                "instance_id": 9,
                "bundle": "/tmp/proof.bundle",
                "php": None,
                "environment_file": "/srv/workspace/.env",
                "units": [],
                "processes": [],
            }

            def command(argv, **_kwargs):
                if "/usr/local/libexec/orbit-instance-node-runtime.py" in argv:
                    return subprocess.CompletedProcess(argv, 0, '{"status":"unchanged"}', "")
                value = "proof\n" if "symbolic-ref" in argv else "abc123\n"
                return subprocess.CompletedProcess(argv, 0, value, "")

            output = io.StringIO()
            with (
                patch.object(GUEST.pathlib, "Path", lambda p: base / str(p).lstrip("/")),
                patch.object(GUEST.subprocess, "run", command),
                patch.object(GUEST.os, "walk", return_value=[]),
                patch.object(GUEST.os, "chown"),
                patch.object(GUEST.sys, "stdin", io.StringIO(json.dumps(inputs))),
                contextlib.redirect_stdout(output),
            ):
                GUEST.phase_main("workspace", json.dumps(container))
            return env.read_text(), json.loads(output.getvalue())["environment"]

    def test_workspace_without_merge_names_writes_the_render_as_before(self):
        written, names = self.run_workspace(DEFAULT_ENV, {"environment": CLONE_RENDER})
        self.assertEqual(written, CLONE_RENDER)
        self.assertEqual(names, {"copied": [], "dropped": []})

    def test_workspace_rewrites_copied_values_that_point_at_the_defaults_site_and_names_them(self):
        seed = DEFAULT_ENV + "VITE_HMR_HOST=default.site.test\nSANCTUM_STATEFUL_DOMAINS=localhost,default.site.test\n"
        hosts = {"from": ["default.site.test"], "to": "feature.site.test"}
        written, names = self.run_workspace(seed, {"environment": CLONE_RENDER, "merge": {**CLONE_MERGE, "hosts": hosts}})
        expected, _, _ = GUEST.merge_environment(seed, CLONE_RENDER, CLONE_MERGE)
        expected = expected.replace("VITE_HMR_HOST=default.", "VITE_HMR_HOST=feature.").replace(",default.", ",feature.")
        self.assertEqual(written, expected)
        self.assertEqual(names["rewritten"], ["SANCTUM_STATEFUL_DOMAINS", "VITE_HMR_HOST"])
        self.assertNotIn("site.test", json.dumps(names))

    def test_workspace_of_a_default_without_such_keys_writes_what_release_2000_wrote(self):
        hosts = {"from": ["default.site.test"], "to": "feature.site.test"}
        before = self.run_workspace(DEFAULT_ENV, {"environment": CLONE_RENDER, "merge": CLONE_MERGE})
        after = self.run_workspace(DEFAULT_ENV, {"environment": CLONE_RENDER, "merge": {**CLONE_MERGE, "hosts": hosts}})
        # Release 2000's file, byte for byte; only the empty `rewritten` list is new.
        self.assertEqual(after[0], before[0])
        self.assertEqual(after[0], GUEST.merge_environment(DEFAULT_ENV, CLONE_RENDER, CLONE_MERGE)[0])
        self.assertEqual(after[1], {**before[1], "rewritten": []})

    def test_workspace_keeps_a_shared_connection_with_the_rendered_values_and_names_it_shared(self):
        # The default's seed .env holds the shared reader connection; the Gateway renders it as the clone's own and names it shared.
        shared = ["FOOD_CATALOG_DATABASE", "FOOD_CATALOG_HOST", "FOOD_CATALOG_PASSWORD", "FOOD_CATALOG_USERNAME"]
        seed = DEFAULT_ENV + "FOOD_CATALOG_HOST=10.44.0.3\nFOOD_CATALOG_DATABASE=catalog\nFOOD_CATALOG_USERNAME=catalog_reader\nFOOD_CATALOG_PASSWORD=reader-secret-1\n"
        render = CLONE_RENDER + 'FOOD_CATALOG_HOST="10.44.0.3"\nFOOD_CATALOG_DATABASE="catalog"\nFOOD_CATALOG_USERNAME="catalog_reader"\nFOOD_CATALOG_PASSWORD="reader-secret-1"\n'
        merge = {**CLONE_MERGE, "own": CLONE_MERGE["own"] + shared, "shared": shared, "hosts": {"from": [], "to": None}}
        written, names = self.run_workspace(seed, {"environment": render, "merge": merge})
        values = {k: v for k, _, v in GUEST.env_blocks(written) if k is not None}
        self.assertEqual({k: values[k] for k in shared}, {"FOOD_CATALOG_DATABASE": "catalog", "FOOD_CATALOG_HOST": "10.44.0.3",
                                                          "FOOD_CATALOG_PASSWORD": "reader-secret-1", "FOOD_CATALOG_USERNAME": "catalog_reader"})
        self.assertEqual(names["shared"], shared)
        self.assertFalse(set(shared) & set(names["copied"] + names["dropped"]))
        self.assertNotIn("reader-secret-1", json.dumps(names))

    def test_workspace_without_a_shared_connection_drops_as_before_and_names_none_shared(self):
        before = self.run_workspace(DEFAULT_ENV, {"environment": CLONE_RENDER, "merge": CLONE_MERGE})
        after = self.run_workspace(DEFAULT_ENV, {"environment": CLONE_RENDER, "merge": {**CLONE_MERGE, "shared": []}})
        self.assertEqual(after[0], before[0])
        self.assertEqual(after[1], {**before[1], "shared": []})
        self.assertEqual(after[1]["dropped"], ["DATABASE_URL", "REPORTS_DB"])

    def test_workspace_of_a_create_admitted_before_hosts_keeps_the_defaults_url(self):
        # Merge names from release 2000's Gateway: no `hosts`, so the file and the names are that release's.
        seed = DEFAULT_ENV + "VITE_HMR_HOST=default.site.test\n"
        written, names = self.run_workspace(seed, {"environment": CLONE_RENDER, "merge": CLONE_MERGE})
        self.assertEqual(written, GUEST.merge_environment(seed, CLONE_RENDER, CLONE_MERGE)[0])
        self.assertIn("VITE_HMR_HOST=default.site.test\n", written)
        self.assertEqual(set(names), {"copied", "dropped"})

    def test_php_workspace_writes_the_shared_php_settings_and_restarts_running_units(
        self,
    ):
        with tempfile.TemporaryDirectory() as folder:
            base = Path(folder)
            (base / "srv/workspace/.git").mkdir(parents=True)
            (base / "srv/workspace/.env").write_text("PRIVATE=env\n")
            unit = {"name": "orbit-frankenphp.service", "content": "[Service]\n"}
            # The unit exists and runs with the settings of before the change.
            (base / "etc/systemd/system").mkdir(parents=True)
            (base / "etc/systemd/system" / unit["name"]).write_text(unit["content"])
            settings = base / "etc/orbit/php/conf.d/99-orbit-runtime.ini"
            settings.parent.mkdir(parents=True)
            settings.write_text("memory_limit = 1G\n")
            container = {
                "workspace": "/srv/workspace",
                "branch": "proof",
                "expected_commit": "abc123",
                "instance_id": 9,
                "bundle": "/tmp/proof.bundle",
                "php": "/opt/orbit-runtime/current/php/8.5/bin/php",
                "environment_file": "/srv/workspace/.env",
                "units": [unit],
                "web_config": {"path": "/etc/orbit/frankenphp.Caddyfile", "content": ":8080 {\n    php_server\n}\n"},
                "processes": [],
            }
            commands = []

            def command(argv, **_kwargs):
                if "/usr/local/libexec/orbit-instance-node-runtime.py" in argv:
                    return subprocess.CompletedProcess(argv, 0, '{"status":"unchanged"}', "")
                commands.append(list(argv))
                value = "proof\n" if "symbolic-ref" in argv else "abc123\n"
                return subprocess.CompletedProcess(argv, 0, value, "")

            class RootOwned(type(base)):
                """A file of the test user reads as root's, as in the container."""

                def stat(self, **kwargs):
                    values = list(super().stat(**kwargs))
                    values[4:6] = [0, 0]
                    return os.stat_result(values)

            def workspace():
                commands.clear()
                with (
                    patch.object(
                        GUEST.pathlib,
                        "Path",
                        lambda p: RootOwned(base / str(p).lstrip("/")),
                    ),
                    patch.object(GUEST.subprocess, "run", command),
                    patch.object(GUEST.os, "walk", return_value=[]),
                    patch.object(GUEST.os, "chown"),
                    patch.object(
                        GUEST.sys,
                        "stdin",
                        io.StringIO(json.dumps({"environment": "PRIVATE=env\n", "merge": NO_MERGE})),
                    ),
                    contextlib.redirect_stdout(io.StringIO()),
                ):
                    GUEST.phase_main("workspace", json.dumps(container))
                return list(commands)

            restart = ["systemctl", "restart", unit["name"]]
            self.assertIn(restart, workspace())
            self.assertNotIn(restart, workspace())
            web_config = base / "etc/orbit/frankenphp.Caddyfile"
            self.assertEqual(web_config.read_text(), container["web_config"]["content"])
            # A stale Caddyfile must repair and restart even with an unchanged unit and PHP settings.
            web_config.write_text("stale\n")
            self.assertIn(restart, workspace())
            self.assertEqual(web_config.read_text(), container["web_config"]["content"])
            self.assertNotIn(restart, workspace())
            written = settings.read_text()
            self.assertEqual(
                written,
                "memory_limit = 1G\n"
                "auto_prepend_file = /opt/orbit-runtime/current/php-data-bridge/bridge/prepend.php\n",
            )
            # The container phase writes the same file for a default and a deployment: both writers hold one content.
            phase = (
                Path(__file__).parents[1] / "ansible/tasks/instance-container-phase.yml"
            ).read_text()
            line = next(
                x for x in phase.splitlines() if "container_php_ini_content:" in x
            )
            self.assertEqual(json.loads(line.split(":", 1)[1].strip()), written)

    def test_agent_preserves_unmanaged_json_and_identity_and_repairs_only_managed_keys(
        self,
    ):
        with tempfile.TemporaryDirectory() as folder:
            base = Path(folder)
            for name in [
                "home/agent/.claude",
                "home/agent/.t3/userdata",
                "home/agent/.config/orbit",
            ]:
                (base / name).mkdir(parents=True, exist_ok=True)
            settings = base / "home/agent/.t3/userdata/settings.json"
            settings.write_text(
                json.dumps({"theme": "dark", "linkedEnvironmentsEnabled": False})
            )
            container = {
                "workspace": "/srv/workspace",
                "clone": {
                    "files": [
                        {
                            "path": "/home/agent/.t3/userdata/settings.json",
                            "uid": os.getuid(),
                            "gid": os.getgid(),
                            "mode": "0600",
                            "json": {"linkedEnvironmentsEnabled": True},
                        }
                    ],
                    "links": [],
                    "model_environment": {
                        "path": "/home/agent/.config/orbit/model.env",
                        "uid": os.getuid(),
                        "gid": os.getgid(),
                        "mode": "0600",
                    },
                },
            }
            commands = []

            def command(argv, **_kwargs):
                if "/usr/local/libexec/orbit-instance-node-runtime.py" in argv:
                    return subprocess.CompletedProcess(argv, 0, '{"status":"unchanged"}', "")
                commands.append(argv)
                # Configured identity stays; the trust list must narrow to this workspace.
                key = argv[-1]
                value = (
                    "/srv/workspace\n"
                    if key == "safe.directory"
                    else "Custom Identity\n"
                )
                return subprocess.CompletedProcess(argv, 0, value, "")

            def inside(value):
                return base / str(value).lstrip("/")

            def run():
                output = io.StringIO()
                with (
                    patch.object(GUEST.pathlib, "Path", inside),
                    patch.object(GUEST.subprocess, "run", command),
                    patch.object(
                        GUEST.sys,
                        "stdin",
                        io.StringIO("ORBIT_MODEL_KEY=private-proof\n"),
                    ),
                    contextlib.redirect_stdout(output),
                ):
                    GUEST.phase_main("agent", json.dumps(container))
                self.assertNotIn("private-proof", output.getvalue())
                return json.loads(output.getvalue())

            self.assertIn("/home/agent/.t3/userdata/settings.json", run()["changes"])
            self.assertEqual(
                json.loads(settings.read_text()),
                {"theme": "dark", "linkedEnvironmentsEnabled": True},
            )
            self.assertEqual(settings.stat().st_mode & 0o777, 0o600)
            self.assertEqual(run()["changes"], [])
            self.assertFalse(any("--replace-all" in argv for argv in commands))
            settings.write_text("{broken")
            with self.assertRaises(json.JSONDecodeError):
                run()


if __name__ == "__main__":
    unittest.main()
