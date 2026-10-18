"""Regression tests for the merge-day script ratchet, without PHP dependencies."""
import importlib.machinery
import importlib.util
import unittest
from pathlib import Path

PATH = Path(__file__).resolve().parent.parent / 'bin/node-scripts-ratchet'
LOADER = importlib.machinery.SourceFileLoader('ratchet', str(PATH))
SPEC = importlib.util.spec_from_loader(LOADER.name, LOADER)
RATCHET = importlib.util.module_from_spec(SPEC)
LOADER.exec_module(RATCHET)


class ScriptRatchetTest(unittest.TestCase):
    def scan(self, files):
        return RATCHET.inventory((name, text.encode()) for name, text in files.items())

    def test_counts_each_script_in_a_file_and_extensions(self):
        counts = self.scan({
            'apps/gateway/app/One.php': "<<<'BASH'\na\nBASH;\n<<<PYTHON\nb\nPYTHON;\n",
            'apps/gateway/extensions/documents/One.php': '<<<"AWK"\na\nAWK;\n',
            'apps/gateway/app/Config.php': "<<<'CADDY'\na\nCADDY;\n",
        })
        self.assertEqual(sum(counts['inline'].values()), 3)
        self.assertEqual(counts['resource'], {})

    def test_counts_libraries_fragments_and_extensionless_programs(self):
        counts = self.scan({
            'apps/gateway/resources/remote/lib/a.py': 'pass',
            'apps/gateway/resources/task-vms/a.sh': 'true',
            'apps/gateway/resources/tasks/check': '#!/usr/bin/env python3\npass',
            'apps/gateway/resources/tasks/bash': '#!/bin/bash\ntrue',
            'apps/gateway/resources/template': 'plain data',
        })
        self.assertEqual(sum(counts['resource'].values()), 4)

    def test_blocks_growth_in_existing_file_even_when_total_falls(self):
        before = self.scan({
            'apps/gateway/app/A.php': "<<<'BASH'\n<<<'BASH'\n<<<'BASH'\n",
            'apps/gateway/app/B.php': "<<<'BASH'\n",
        })
        after = self.scan({'apps/gateway/app/B.php': "<<<'BASH'\n<<<'BASH'\n"})
        self.assertEqual(len(RATCHET.growth(before, after)), 1)

    def test_blocks_new_location_and_resource_even_when_others_are_deleted(self):
        before = self.scan({
            'apps/gateway/app/A.php': "<<<'BASH'\n",
            'apps/gateway/resources/a.py': 'pass',
        })
        after = self.scan({
            'apps/gateway/app/B.php': "<<<'BASH'\n",
            'apps/gateway/resources/b.py': 'pass',
        })
        self.assertEqual(len(RATCHET.growth(before, after)), 2)
        self.assertEqual(RATCHET.growth(before, before), [])
        self.assertEqual(RATCHET.growth(before, self.scan({})), [])


if __name__ == '__main__':
    unittest.main()
