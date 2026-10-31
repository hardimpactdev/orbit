"""Metrics inspection returns public state and never engine environment values."""

import importlib.util
import json
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

spec = importlib.util.spec_from_file_location("metrics_inspect", Path(__file__).parent / "scripts/inspect.py")
inspection = importlib.util.module_from_spec(spec)
spec.loader.exec_module(inspection)


class MetricsInspectionTest(unittest.TestCase):
    def setUp(self):
        self.data = {"kind": "container", "name": "orbit-metrics-grafana",
                     "labels": {"com.orbit.managed": "metrics", "com.orbit.metrics.service": "grafana"},
                     "hash": "a" * 64}
        self.labels = {**self.data["labels"], "com.orbit.metrics.spec-hash": "a" * 64}

    def runner(self, state, labels=None):
        def run(argv, **_):
            if "ls" in argv:
                return SimpleNamespace(returncode=0, stdout=self.data["name"] + "\n")
            self.assertNotIn("{{json .}}", argv)
            return SimpleNamespace(returncode=0, stdout=json.dumps(labels or self.labels) + "\n" + json.dumps(state))
        return run

    def test_only_healthy_matching_container_is_current(self):
        for status, health, current in (("running", "healthy", True), ("running", "starting", False),
                                        ("running", "unhealthy", False), ("exited", "healthy", False)):
            with self.subTest(status=status, health=health):
                result = inspection.inspect(self.data, self.runner({"Status": status, "Health": {"Status": health}}))
                self.assertEqual(result["current"], current)
                self.assertNotIn("labels", result)
        labels = {**self.labels, "com.orbit.metrics.spec-hash": "b" * 64}
        self.assertFalse(inspection.inspect(self.data, self.runner({"Status": "running", "Health": {"Status": "healthy"}}, labels))["current"])

    def test_absence_requires_successful_engine_listing_and_foreign_ownership_is_refused(self):
        absent = lambda *_, **__: SimpleNamespace(returncode=0, stdout="")
        self.assertEqual(inspection.inspect(self.data, absent)["state"], "absent")
        unavailable = lambda *_, **__: SimpleNamespace(returncode=1, stdout="")
        with self.assertRaises(ValueError):
            inspection.inspect(self.data, unavailable)
        with self.assertRaises(ValueError):
            inspection.inspect(self.data, self.runner({"Status": "running"}, {"com.orbit.managed": "operator"}))


class MetricsOwnershipTest(unittest.TestCase):
    def test_existing_directory_requires_its_managed_marker_before_any_write(self):
        owner_spec = importlib.util.spec_from_file_location("metrics_owner", Path(__file__).parent / "scripts/owner.py")
        owner = importlib.util.module_from_spec(owner_spec)
        owner_spec.loader.exec_module(owner)
        with tempfile.TemporaryDirectory() as directory, patch.object(owner, "ROOT", Path(directory)):
            root = Path(directory)
            marker = root / ".orbit-owner"
            with self.assertRaises(FileNotFoundError):
                owner.verify([str(marker)])
            marker.write_text("foreign\n")
            with self.assertRaises(ValueError):
                owner.verify([str(marker)])
            marker.write_text("metrics\n")
            self.assertEqual(owner.verify([str(marker), str(root / "future")]), {"ready": True})
            link = root / "link"
            link.symlink_to(marker)
            with self.assertRaises(ValueError):
                owner.verify([str(link)])
            self.assertEqual(marker.read_text(), "metrics\n")


class MetricsExporterAssetsTest(unittest.TestCase):
    def module(self, name):
        spec = importlib.util.spec_from_file_location(name, Path(__file__).parent / "scripts" / (name + ".py"))
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        return module

    def test_exporter_reads_existing_config_and_actual_service_without_changing_the_file(self):
        module = self.module("exporter_observe")
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "unit"
            path.write_text("# managed\nconfiguration")
            run = lambda *_, **__: SimpleNamespace(returncode=3, stdout="inactive\n")
            self.assertEqual(module.observe({"path": str(path), "marker": "# managed\n", "service": "exporter"}, run),
                             {"configuration": "# managed\nconfiguration", "service_active": False})
            with self.assertRaises(ValueError):
                module.observe({"path": str(path), "marker": "# different\n", "service": "exporter"}, run)
            self.assertEqual(path.read_text(), "# managed\nconfiguration")

    def test_verified_artifact_is_idempotent_and_checksum_failure_preserves_the_old_binary(self):
        module = self.module("artifact")
        import hashlib
        with tempfile.TemporaryDirectory() as directory:
            source = Path(directory) / "source"
            target = Path(directory) / "binary"
            source.write_bytes(b"new pinned executable")
            target.write_bytes(b"old executable")
            data = {"path": str(target), "url": source.as_uri(), "sha256": "a" * 64}
            with self.assertRaises(ValueError):
                module.install(data)
            self.assertEqual(target.read_bytes(), b"old executable")
            data["sha256"] = hashlib.sha256(source.read_bytes()).hexdigest()
            self.assertEqual(module.install(data), {"ready": True})
            self.assertEqual(target.read_bytes(), source.read_bytes())
            self.assertEqual(target.stat().st_mode & 0o777, 0o755)
            source.unlink()
            self.assertEqual(module.install(data), {"ready": True})
            self.assertEqual(list(Path(directory).glob(".orbit-metrics-*")), [])
