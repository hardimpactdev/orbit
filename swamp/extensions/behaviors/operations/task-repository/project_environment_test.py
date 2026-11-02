"""The shared setup environment keeps phpdotenv value boundaries."""
import importlib.util
import os
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

SPEC = importlib.util.spec_from_file_location(
    "project_environment", Path(__file__).parent / "scripts/project_environment.py"
)
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)


class ProjectEnvironment(unittest.TestCase):
    def test_file_guards_refuse_links_nonfiles_writable_and_oversized_files(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            target = root / 'target'
            target.write_text('KEY=value\n')
            env = root / '.env'
            env.symlink_to(target)
            with self.assertRaisesRegex(ValueError, '^instance.step_layout_invalid$'):
                MODULE.load_project_environment(root)
            env.unlink()
            os.mkfifo(env)
            with self.assertRaisesRegex(ValueError, '^instance.step_layout_invalid$'):
                MODULE.load_project_environment(root)
            env.unlink()
            env.write_text('KEY=value\n')
            env.chmod(0o666)
            with self.assertRaisesRegex(ValueError, '^instance.step_layout_invalid$'):
                MODULE.load_project_environment(root)
            env.chmod(0o600)
            env.write_text('x' * 1048577)
            with self.assertRaisesRegex(ValueError, '^instance.step_layout_invalid$'):
                MODULE.load_project_environment(root)

    def test_file_guards_require_the_current_owner_and_group(self):
        with tempfile.TemporaryDirectory() as folder:
            Path(folder, '.env').write_text('KEY=value\n')
            for name in ['getuid', 'getgid']:
                wrong = getattr(os, name)() + 1
                with self.subTest(name=name), patch.object(MODULE.os, name, return_value=wrong), \
                        self.assertRaisesRegex(ValueError, '^instance.step_layout_invalid$'):
                    MODULE.load_project_environment(folder)

    def test_multiline_whitespace_only_value_keeps_its_first_line(self):
        value = ' \t\n  '
        self.assertEqual(MODULE.parse_project_environment('DB_PASSWORD="' + value + '"\n'), {'DB_PASSWORD': value})
        self.assertEqual(MODULE.parse_project_environment('DB_PASSWORD="   "\n'), {'DB_PASSWORD': '   '})

    def test_quoted_values_keep_multiline_unicode_and_supported_escapes(self):
        self.assertEqual(MODULE.parse_project_environment(
            'PEM="first\nsecond"\nUNICODE="left\u2028right"\n'
            'ESCAPES="a\\tb\\fc\\vd\\ne\\rf\\$g\\\\h\\\"i"\n'
        ), {"PEM": "first\nsecond", "UNICODE": "left\u2028right",
            "ESCAPES": 'a\tb\fc\vd\ne\rf$g\\h"i'})

    def test_comments_do_not_become_value_bytes(self):
        self.assertEqual(MODULE.parse_project_environment(
            "# header\r\nAPP_KEY=abcdefgh#comment\r\n"
            "LITERAL='a#b\\n' # ignored\r\nEMPTY=\r\n"
        ), {"APP_KEY": "abcdefgh", "LITERAL": "a#b\\n", "EMPTY": ""})

    def test_export_and_only_cr_lf_line_boundaries_match_phpdotenv(self):
        for separator in ['\n', '\r\n', '\r']:
            with self.subTest(separator=separator):
                self.assertEqual(MODULE.parse_project_environment(separator.join([
                    'export\tK="first', 'second" # comment', 'S="ab\u2028cd"',
                    'A=abcdefg#unspaced', 'B=hunter2 # spaced', '',
                ])), {'K': 'first\nsecond', 'S': 'ab\u2028cd', 'A': 'abcdefg', 'B': 'hunter2'})

    def test_quotes_and_backslashes_are_values_not_an_extra_unescape_pass(self):
        self.assertEqual(MODULE.parse_project_environment('K="ab\\\\"\nL="a\\\"b"\nD="\\$HOME"\n'),
                         {'K': 'ab\\', 'L': 'a"b', 'D': '$HOME'})

    def test_file_local_references_resolve_without_reading_process_environment(self):
        self.assertEqual(MODULE.parse_project_environment(
            'A=local\nB="q${A}z"\nC=pre${A}\nD="\\${A}"\nE=\'${A}\'\nF="${NOT_STORED}"\n'
        ), {'A': 'local', 'B': 'qlocalz', 'C': 'prelocal', 'D': '${A}', 'E': '${A}', 'F': '${NOT_STORED}'})
        self.assertEqual(MODULE.parse_project_environment('A=hello\nB=A\nC="${${B}}"\n'),
                         {'A': 'hello', 'B': 'A', 'C': 'hello'})

    def test_unquoted_literal_quotes_and_trailing_ascii_whitespace_match_phpdotenv(self):
        self.assertEqual(MODULE.parse_project_environment('A=ab"cd\nB=foo\v\nC="bar"\f#comment\n'),
                         {'A': 'ab"cd', 'B': 'foo', 'C': 'bar'})

    def test_multiline_entry_boundary_matches_phpdotenv_even_inside_a_literal(self):
        with self.assertRaisesRegex(MODULE.ProjectEnvironmentRefusal, 'instance.step_not_admitted'):
            MODULE.parse_project_environment('K0=\'b="\'\nK1="next"\n')
        with self.assertRaisesRegex(MODULE.ProjectEnvironmentRefusal, 'instance.step_not_admitted'):
            MODULE.parse_project_environment("K='multi\nline'\n")
        self.assertEqual(MODULE.parse_project_environment('K=\'literal="\'\n'), {'K': 'literal="'})

    def test_refusal_keeps_unparsed_lines_only_in_private_redaction_targets(self):
        text = '# harmless comment\nKEY="valid-synthetic"\nunparsed-private-value\n'
        with self.assertRaises(MODULE.ProjectEnvironmentRefusal) as raised:
            MODULE.parse_project_environment(text)
        self.assertEqual(str(raised.exception), 'instance.step_not_admitted')
        self.assertIn('unparsed-private-value', raised.exception.redaction_targets)
        self.assertNotIn('# harmless comment', raised.exception.redaction_targets)
        self.assertNotIn('unparsed-private-value', repr(raised.exception))

    def test_malformed_or_process_control_values_still_refuse(self):
        for text in ["A=1\nA=2", "LD_PRELOAD=x", "BASH_ENV=x", 'A="open',
                     'A="value"trailing', 'A="bad\\q"', "A=x\0y", 'A=two words']:
            with self.subTest(text=text), self.assertRaisesRegex(ValueError, "instance.step_not_admitted"):
                MODULE.parse_project_environment(text)

    def test_invalid_utf8_file_keeps_the_named_refusal(self):
        with tempfile.TemporaryDirectory() as folder:
            Path(folder, '.env').write_bytes(b'K="a\xffb"\n')
            with self.assertRaisesRegex(MODULE.ProjectEnvironmentRefusal, '^instance.step_not_admitted$'):
                MODULE.load_project_environment(folder)


if __name__ == "__main__":
    unittest.main()
