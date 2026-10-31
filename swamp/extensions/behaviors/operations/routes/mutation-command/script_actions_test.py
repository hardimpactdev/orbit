"""Exercise URL updates and compensation against real disposable files."""
import importlib.util
import os
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

SCRIPT = Path(__file__).parent / "scripts/write_environment.py"
spec = importlib.util.spec_from_file_location("route_environment", SCRIPT)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class EnvironmentWrites(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(dir=os.environ.get("TMPDIR"))
        self.addCleanup(self.temp.cleanup)
        self.base = Path(self.temp.name)
        self.owner = SimpleNamespace(pw_name="fixture", pw_dir=str(self.base), pw_uid=os.getuid(), pw_gid=os.getgid())
        self.value = {"name": "instance.write-development-url", "input": {"user": "fixture", "directory": str(self.base), "require_artisan": True, "url": "https://new.test", "app_key_stored": True, "app_name_stored": True}}
        self.private = {"app_key": "APP_KEY=synthetic-admission-key", "app_name": 'APP_NAME="Example"'}
        (self.base / "artisan").touch()
        self.account = patch.object(module.pwd, "getpwnam", return_value=self.owner)
        self.account.start()
        self.addCleanup(self.account.stop)

    def test_forward_and_restore_preserve_live_key_and_other_cached_fields(self):
        env = self.base / ".env"
        env.write_text("APP_KEY=keep-live-key\nAPP_URL=https://old.test\nOTHER=keep\n")
        cache = self.base / "bootstrap/cache/config.php"
        cache.parent.mkdir(parents=True)
        cache.write_text("<?php return ['app' => ['url' => 'https://old.test', 'key' => 'keep-live-key'], 'other' => ['url' => 'https://unrelated.test']];")
        module.apply(self.value, self.private)
        self.assertIn("APP_URL=https://new.test", env.read_text())
        self.assertIn("APP_KEY=keep-live-key", env.read_text())
        self.assertIn("'https://unrelated.test'", cache.read_text())
        self.value["input"]["url"] = "https://old.test"
        module.apply(self.value, self.private)
        self.assertIn("APP_URL=https://old.test", env.read_text())
        self.assertIn("'url' => 'https://old.test'", cache.read_text())
        self.assertNotIn("synthetic-admission-key", env.read_text())

    def test_duplicate_url_refuses_before_any_write(self):
        env = self.base / ".env"
        before = "APP_URL=first\nAPP_URL=second\n"
        env.write_text(before)
        with self.assertRaises(ValueError):
            module.apply(self.value, self.private)
        self.assertEqual(env.read_text(), before)

    def test_invalid_cache_refuses_before_environment_write(self):
        env = self.base / ".env"
        env.write_text("APP_URL=https://old.test\n")
        cache = self.base / "bootstrap/cache/config.php"
        cache.parent.mkdir(parents=True)
        cache.write_text("<?php return ['app' => ['url' => some_function()]];")
        with self.assertRaises(ValueError):
            module.apply(self.value, self.private)
        self.assertEqual(env.read_text(), "APP_URL=https://old.test\n")

    def test_non_laravel_directory_remains_untouched(self):
        (self.base / "artisan").unlink()
        module.apply(self.value, self.private)
        self.assertFalse((self.base / ".env").exists())
