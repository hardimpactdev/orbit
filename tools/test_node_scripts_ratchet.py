"""Regression tests for the Gateway script inventory and its total-count ratchet."""
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

    def test_counts_unknown_heredoc_labels_and_excludes_only_data_labels(self):
        source = "\n".join(f"<<<'{label}'\ncontent\n{label};" for label in [
            'BASH', 'BOOTSTRAP_DNS', 'BASH_WRAP', 'SCRIPT', 'SQL', 'CADDY', 'FPM',
        ])
        self.assertEqual(RATCHET.inline_count(source), 4)

    def test_counts_argv_and_shell_string_scripts(self):
        source = '''
            ['sh', '-c', 'one']; ['bash', '-ceu', 'two'];
            ['python3', '-I', '-c', 'three']; ['/bin/sh', '-ec', 'four'];
            "bash -c five"; ['bash', '-seu', '--']; ['python3', 'file.py'];
        '''
        self.assertEqual(RATCHET.inline_count(source), 5)

    def test_counts_literal_scripts_with_prefixes_and_multiple_flags(self):
        source = "'sudo sh -c true'; ' bash -eu -c true'; 'f() { python3 -I -c true; }'"
        self.assertEqual(RATCHET.inline_count(source), 3)

    def test_does_not_double_count_shell_inside_heredoc(self):
        self.assertEqual(RATCHET.inline_count("<<<'SCRIPT'\nsh -c 'true'\nSCRIPT;\n"), 1)

    def test_counts_script_files_across_gateway_and_extension_resources(self):
        counts = self.scan({
            'apps/gateway/resources/remote/lib/a.py': 'pass',
            'apps/gateway/extensions/ai-router/resources/start.bash': 'true',
            'apps/gateway/extensions/tasks/resources/check': '#!/bin/sh\ntrue',
            'apps/gateway/extensions/ai-router/resources/server.php': '<?php echo 1;',
            'apps/gateway/resources/tasks/composer': '<?php echo 1;',
            'apps/gateway/resources/private-dns/serve.php': '<?php echo 1;',
            'apps/gateway/resources/scripts/horizon-queue.php': '<?php echo 1;',
            'apps/gateway/tests/fixture.py': 'pass',
            'apps/gateway/bin/probe': '#!/usr/bin/env ruby\nexit',
            'apps/gateway/app/Action.php': '<?php',
            'apps/gateway/resources/views/page.blade.php': '<?php echo 1;',
            'apps/gateway/resources/data.json': '{}',
        })
        self.assertEqual(sum(counts['files'].values()), 9)
        self.assertEqual(counts['inline'], {})

    def test_moves_between_files_and_into_extensions_are_neutral(self):
        before = self.scan({
            'apps/gateway/app/One.php': "<<<'SCRIPT'\ntrue\nSCRIPT;\n",
            'apps/gateway/resources/server.py': 'pass',
        })
        after = self.scan({
            'apps/gateway/extensions/ai-router/src/One.php': "<<<'SCRIPT'\ntrue\nSCRIPT;\n",
            'apps/gateway/extensions/ai-router/resources/server.py': 'pass',
        })
        self.assertEqual(RATCHET.growth(before, after), [])

    def test_only_total_growth_fails_and_categories_cannot_offset_each_other(self):
        before = self.scan({'apps/gateway/app/A.php': "['sh', '-c', 'true'];"})
        after = self.scan({'apps/gateway/app/B.php': "['sh', '-c', 'true']; ['bash', '-c', 'true'];"})
        self.assertEqual(len(RATCHET.growth(before, after)), 1)
        self.assertEqual(RATCHET.growth(before, self.scan({})), [])
        self.assertEqual(len(RATCHET.growth(before, self.scan({
            'apps/gateway/resources/a.py': 'pass',
        }))), 1)


if __name__ == '__main__':
    unittest.main()
