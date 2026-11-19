"""The bundled skills match the reviewed snapshot of Orbit's pinned Swamp runtime."""
import hashlib
import json
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]


class SwampSkillsTest(unittest.TestCase):
    def test_bundled_skills_match_pinned_runtime(self):
        skills = ROOT / '.agents/swamp-upstream/skills'
        manifest = json.loads((skills.parent / 'swamp-source.json').read_text())
        version = re.search(r'^swampVersion: (\S+)$', (ROOT / 'swamp/.swamp.yaml').read_text(), re.M)[1]
        refresh = 'Refresh both bundled skills and swamp-source.json; see docs/contributor-guide.md#refresh-the-bundled-swamp-skills'
        self.assertEqual(manifest['swampVersion'], version, refresh)
        self.assertRegex(manifest['commit'], r'^[0-9a-f]{40}$')
        self.assertTrue(manifest['commit'].startswith(version.split('-sha.')[1]), refresh)
        expected = manifest['files']
        actual = {
            str(path.relative_to(skills)): hashlib.sha256(path.read_bytes()).hexdigest()
            for name in ('swamp', 'swamp-getting-started')
            for path in (skills / name).rglob('*') if path.is_file()
        }
        self.assertTrue(expected, 'The upstream bundled file manifest must not be empty')
        self.assertEqual(set(expected), set(actual), refresh)
        for path, digest in expected.items():
            with self.subTest(path=path):
                self.assertEqual(digest, actual[path], refresh)


if __name__ == '__main__':
    unittest.main()
