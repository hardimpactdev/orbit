"""Real flock and temporary-file regressions for the Node publication transaction."""

import hashlib
import importlib.util
import multiprocessing
import os
import subprocess
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

SOURCE = Path(__file__).resolve().parents[1] / "ansible/module_utils/orbit_caddy_publish.py"
SPEC = importlib.util.spec_from_file_location("caddy_publish", SOURCE)
PUBLISH = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(PUBLISH)


def checksum(value):
    return hashlib.sha256(value.encode()).hexdigest()


def tool(argv, **kwargs):
    done = subprocess.run(argv, capture_output=True, text=True, check=False, timeout=8, **kwargs)
    return done.returncode, done.stdout, done.stderr


class CaddyPublishTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="orbit-publish-")
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.live = self.root / "Caddyfile"
        self.old = "# original\n"
        self.live.write_text(self.old)
        self.live.chmod(0o640)
        self.bin = self.root / "bin"
        self.bin.mkdir()
        self.calls = []
        self.env = patch.dict(os.environ, {"PATH": str(self.bin) + ":" + os.environ["PATH"]})
        self.env.start()
        self.addCleanup(self.env.stop)
        self.script("runuser", 'shift 3; exec "$@"')
        self.script("caddy", 'test "$1" = validate && ! /usr/bin/grep -q INVALID "$3"')
        self.script("systemctl", 'test "$1 $2" = "reload caddy"')
        self.validation = None

    def script(self, name, body):
        executable = self.bin / name
        executable.write_text("#!/bin/sh\n" + body + "\n")
        executable.chmod(0o755)

    def run_tool(self, argv, **kwargs):
        self.calls.append(argv)
        if "validate" in argv:
            candidate = Path(argv[argv.index("--config") + 1])
            self.assertEqual(candidate.parent, self.live.parent)
            self.validation = candidate.stat()
        return tool(argv, **kwargs)

    def publish(self, text, base=None, run=None):
        return PUBLISH.publish(text, checksum(self.old) if base is None else base,
                               self.run_tool if run is None else run, live=self.live,
                               uid=os.getuid(), gid=os.getgid())

    def assert_untouched(self, before, text):
        self.assertEqual(self.live.read_text(), text)
        after = self.live.stat()
        self.assertEqual((after.st_ino, after.st_mode, after.st_mtime_ns),
                         (before.st_ino, before.st_mode, before.st_mtime_ns))
        self.assertFalse(any("reload" in command for command in self.calls))
        self.assertEqual(list(self.root.glob(".orbit-candidate-*")), [])

    def test_same_checksum_publishes_exact_validated_inode_and_reloads(self):
        self.publish("# first site\n")
        self.assertEqual(self.live.read_text(), "# first site\n")
        self.assertEqual(self.live.stat().st_ino, self.validation.st_ino)
        self.assertEqual(self.validation.st_mode & 0o777, 0o640)
        self.assertEqual(self.live.stat().st_gid, os.getgid())
        self.assertTrue(any("reload" in command for command in self.calls))
        lock = self.root / ".orbit-publish.lock"
        self.assertEqual(lock.stat().st_mode & 0o777, 0o600)
        self.assertEqual(lock.stat().st_uid, os.getuid())
        inode = lock.stat().st_ino
        self.publish("# first site\n", checksum("# first site\n"))
        self.assertEqual(lock.stat().st_ino, inode)

    def test_old_writer_between_read_and_apply_refuses_with_both_checksum_origins(self):
        # The pre-release writer ignores the lock and publishes after composition.
        self.live.write_text("# other old-release writer site\n")
        before = self.live.stat()
        with self.assertRaises(PUBLISH.PublicationFailure) as refused:
            self.publish("# stale candidate\n")
        error = refused.exception
        self.assertEqual(error.code, "caddy.composed_from_live_mismatch")
        self.assertEqual(error.facts, {
            "composed_from_sha256": checksum(self.old),
            "live_sha256": checksum("# other old-release writer site\n"),
        })
        self.assertIn("composed-from SHA-256", str(error))
        self.assertIn("live SHA-256", str(error))
        self.assert_untouched(before, "# other old-release writer site\n")

    def test_two_interleaved_writers_preserve_the_first_site_and_refuse_the_second(self):
        # Pause the first writer inside candidate validation; the second queues
        # on real flock, then compares its original base after the first reload.
        context = multiprocessing.get_context("fork")
        validating, queued, finish = context.Event(), context.Event(), context.Event()
        result = context.Queue()

        def first_run(argv, **kwargs):
            if "validate" in argv:
                validating.set()
                if not finish.wait(2):
                    raise AssertionError("fixture did not release validation")
            return tool(argv, **kwargs)

        def first():
            try:
                self.publish("# first site\n", run=first_run)
                result.put("first-published")
            except (PUBLISH.PublicationFailure, AssertionError, OSError) as error:
                result.put(str(error))

        def second_run(argv, **kwargs):
            if argv[0] == "flock":
                queued.set()
            return tool(argv, **kwargs)

        def second():
            try:
                self.publish("# second stale site\n", run=second_run)
                result.put("second-published")
            except PUBLISH.PublicationFailure as error:
                result.put(error.code)

        processes = [context.Process(target=first), context.Process(target=second)]
        try:
            processes[0].start()
            self.assertTrue(validating.wait(2))
            processes[1].start()
            self.assertTrue(queued.wait(2))
            # The new writer owns the native lock even though the flock utility exited.
            descriptor = os.open(self.root / ".orbit-publish.lock", os.O_RDONLY)
            try:
                self.assertNotEqual(tool(["flock", "-n", str(descriptor)], pass_fds=(descriptor,))[0], 0)
            finally:
                os.close(descriptor)
            finish.set()
            for process in processes:
                process.join(2)
                self.assertFalse(process.is_alive())
            self.assertEqual({result.get(timeout=1), result.get(timeout=1)},
                             {"first-published", "caddy.composed_from_live_mismatch"})
            self.assertEqual(self.live.read_text(), "# first site\n")
        finally:
            finish.set()
            for process in processes:
                if process.pid is not None:
                    if process.is_alive():
                        process.terminate()
                    process.join(2)
            result.close()

    def test_invalid_candidate_keeps_live_bytes_metadata_and_skips_reload(self):
        before = self.live.stat()
        with self.assertRaises(PUBLISH.PublicationFailure) as refused:
            self.publish("INVALID\n")
        self.assertEqual(refused.exception.code, "caddy.validation_failed")
        self.assert_untouched(before, self.old)

    def test_reload_failure_retains_validated_publication_and_frees_lock(self):
        self.script("systemctl", "echo reload-failed >&2; exit 9")
        with self.assertRaises(PUBLISH.PublicationFailure) as refused:
            self.publish("# validated new site\n")
        self.assertEqual(refused.exception.code, "caddy.reload_failed")
        self.assertTrue(refused.exception.changed)
        self.assertEqual(self.live.read_text(), "# validated new site\n")
        self.assertEqual(self.live.stat().st_ino, self.validation.st_ino)
        self.script("systemctl", "exit 0")
        self.publish("# validated new site\n", checksum("# validated new site\n"))

    def test_missing_and_empty_composed_bases_are_checked_not_unconditional(self):
        self.live.unlink()
        self.publish("# newly initialized\n", checksum(""))
        self.live.write_text("# writer appeared\n")
        with self.assertRaises(PUBLISH.PublicationFailure):
            self.publish("# would lose writer\n", checksum(""))

    def test_lock_wait_uses_five_second_bound_and_busy_refusal_keeps_live_file(self):
        lock = self.root / ".orbit-publish.lock"
        descriptor = os.open(lock, os.O_CREAT | os.O_RDWR, 0o600)
        self.addCleanup(os.close, descriptor)
        self.assertEqual(tool(["flock", "-n", str(descriptor)], pass_fds=(descriptor,))[0], 0)

        def shortened(argv, **kwargs):
            self.assertEqual(argv[:7], ["flock", "--exclusive", "--timeout", "5",
                                       "--conflict-exit-code", "75", argv[6]])
            # Exercise actual tool contention without spending five seconds per unit case.
            return tool([*argv[:3], "0.02", *argv[4:]], **kwargs)

        before = self.live.stat()
        with self.assertRaises(PUBLISH.PublicationFailure) as refused:
            self.publish("# blocked\n", run=shortened)
        self.assertEqual(refused.exception.code, "caddy.publish_busy")
        self.assert_untouched(before, self.old)

    def test_holder_death_closes_the_descriptor_and_releases_the_lock(self):
        context = multiprocessing.get_context("fork")
        locked = context.Event()

        def hold(argv, **kwargs):
            result = tool(argv, **kwargs)
            if argv[0] == "flock":
                locked.set()
                context.Event().wait(5)
            return result

        process = context.Process(target=lambda: self.publish("# dies\n", run=hold))
        process.start()
        try:
            self.assertTrue(locked.wait(2))
            process.terminate()
            process.join(2)
            self.publish("# after holder death\n")
            self.assertEqual(self.live.read_text(), "# after holder death\n")
        finally:
            if process.is_alive():
                process.kill()
            process.join(2)

    def test_invalid_lock_or_checksum_refuses_without_touching_live_file(self):
        before = self.live.stat()
        with self.assertRaises(PUBLISH.PublicationFailure) as refused:
            self.publish("# bad base\n", "")
        self.assertEqual(refused.exception.code, "caddy.composed_from_invalid")
        outside = self.root / "outside"
        outside.write_text("do not lock or change")
        (self.root / ".orbit-publish.lock").symlink_to(outside)
        with self.assertRaises(PUBLISH.PublicationFailure) as refused:
            self.publish("# bad lock\n")
        self.assertEqual(refused.exception.code, "caddy.publish_lock_invalid")
        self.assertEqual(outside.read_text(), "do not lock or change")
        self.assert_untouched(before, self.old)


if __name__ == "__main__":
    unittest.main()
