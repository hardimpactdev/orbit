"""Agent delivery identity includes extension crates and ignores unrelated Gateway code."""
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parent.parent


class AgentSourceCommitTest(unittest.TestCase):
    def test_extension_change_moves_delivery_identity(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            def git(*args):
                return subprocess.check_output(['git', '-C', str(root), *args], text=True).strip()
            def commit(path, content):
                target = root / path
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_text(content)
                git('add', '.')
                git('commit', '-qm', 'fixture')
                return git('rev-parse', 'HEAD')
            git('init', '-q')
            git('config', 'user.email', 'fixture@example.test')
            git('config', 'user.name', 'Fixture')
            script = root / 'apps/agent/scripts/source-commit'
            script.parent.mkdir(parents=True)
            shutil.copy2(ROOT / 'apps/agent/scripts/source-commit', script)
            core = commit('apps/agent/src/lib.rs', 'core')
            native = commit('apps/gateway/extensions/documents/agent/src/lib.rs', 'extension')
            commit('apps/gateway/app/Controller.php', '<?php')
            self.assertEqual(subprocess.check_output([str(script)], text=True).strip(), native)
            self.assertEqual(subprocess.check_output([str(script), core], text=True).strip(), core)


if __name__ == '__main__':
    unittest.main()
