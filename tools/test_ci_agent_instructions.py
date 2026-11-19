"""The root AGENTS.md loads into every agent session, so it stays a short map that cannot grow back unnoticed."""
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
AGENTS = ROOT / 'AGENTS.md'
# Measured in bytes, as `wc -c` counts them.
LIMIT = 3000


class RootAgentInstructionsTest(unittest.TestCase):
    def test_the_root_file_stays_within_its_size(self):
        size = len(AGENTS.read_bytes())
        self.assertLessEqual(size, LIMIT, f'AGENTS.md has {size} bytes; the limit is {LIMIT}. Move detail to the file '
                                          'where it applies and keep the root file a map.')

    def test_headings_that_other_files_link_to_stay(self):
        headings = re.findall(r'^## (.+)$', AGENTS.read_text(), re.MULTILINE)
        self.assertIn('Quality bar', headings)
        self.assertIn('Checks and resources', headings)

    def test_every_local_link_resolves(self):
        for target in re.findall(r'\]\(([^)#]+)(?:#[^)]*)?\)', AGENTS.read_text()):
            if not target.startswith('http'):
                self.assertTrue((ROOT / target).exists(), target)


if __name__ == '__main__':
    unittest.main()
