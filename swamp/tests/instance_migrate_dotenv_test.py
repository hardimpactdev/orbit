"""Regression cases for the installed phpdotenv parser's literal syntax."""
import importlib.util
from pathlib import Path
import unittest

MODULE = Path(__file__).resolve().parents[1] / 'ansible/library/orbit_migrate_env.py'
SPEC = importlib.util.spec_from_file_location('orbit_migrate_dotenv', MODULE)
guard = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(guard)


class InstanceMigrateDotenvTest(unittest.TestCase):
    def test_even_backslashes_before_a_closing_quote_do_not_swallow_the_next_host(self):
        for count in (2, 4, 6):
            with self.subTest(backslashes=count):
                text = 'PRIVATE="path' + '\\' * count + '"\nDB_HOST=127.0.0.1\nDB_PORT=3308\n'
                values = guard.dotenv(text)
                self.assertEqual(values['PRIVATE'], 'path' + '\\' * (count // 2))
                self.assertEqual(values['DB_HOST'], '127.0.0.1')
                self.assertEqual(values['DB_PORT'], '3308')

    def test_odd_backslash_parity_preserves_an_escaped_double_quote(self):
        text = 'PRIVATE="before' + '\\' * 3 + '"after"\nDB_HOST=localhost\n'
        self.assertEqual(guard.dotenv(text), {'PRIVATE': 'before\\"after', 'DB_HOST': 'localhost'})

    def test_single_and_double_quoted_names_are_service_keys(self):
        values = guard.dotenv("export 'DB_HOST' = '127.0.0.1'\n\"DB_PORT\"=3308\n'FOOD_DB_CONNECTION'=mysql\n")
        self.assertEqual(values, {'DB_HOST': '127.0.0.1', 'DB_PORT': '3308', 'FOOD_DB_CONNECTION': 'mysql'})

    def test_double_quoted_escapes_match_phpdotenv_without_variable_expansion(self):
        escapes = {'f': '\f', 'n': '\n', 'r': '\r', 't': '\t', 'v': '\v', '"': '"', '\\': '\\', '$': '$'}
        for escape, decoded in escapes.items():
            with self.subTest(escape=escape):
                values = guard.dotenv('PRIVATE="before\\' + escape + 'after"\nDB_HOST=localhost\n')
                self.assertEqual(values['PRIVATE'], 'before' + decoded + 'after')
                self.assertEqual(values['DB_HOST'], 'localhost')
        self.assertEqual(guard.dotenv('DB_HOST="${PRIVATE}"\nPRIVATE=never-expand\n')['DB_HOST'], '${PRIVATE}')

    def test_backslashes_in_single_quotes_and_unquoted_values_are_literal(self):
        single = "PRIVATE='path" + '\\' + "'\nDB_HOST=localhost\n"
        self.assertEqual(guard.dotenv(single), {'PRIVATE': 'path\\', 'DB_HOST': 'localhost'})
        self.assertEqual(guard.dotenv(r'PRIVATE=path\q'), {'PRIVATE': r'path\q'})
        self.assertEqual(guard.dotenv("PRIVATE='a\\n\\$b\\\"c'"), {'PRIVATE': 'a\\n\\$b\\"c'})

    def test_hash_starts_unquoted_comments_with_or_without_whitespace(self):
        values = guard.dotenv('A=first#comment\nB=second \t #comment\nC="third#value"#comment\nD=\'#fourth\' #comment\n')
        self.assertEqual(values, {'A': 'first', 'B': 'second', 'C': 'third#value', 'D': '#fourth'})

    def test_trimmed_assignment_spacing_keeps_quoted_value_spacing(self):
        values = guard.dotenv(' \texport DB_HOST \t= " 127.0.0.1 "\t #comment\r\nDB_PORT = 3308  \r\nEMPTY= #comment\nBARE\n')
        self.assertEqual(values, {'DB_HOST': ' 127.0.0.1 ', 'DB_PORT': '3308', 'EMPTY': '', 'BARE': None})

    def test_multiline_private_values_do_not_create_service_assignments(self):
        text = 'PRIVATE="first\r\nDB_HOST=127.0.0.1\r\nMAIL_HOST=localhost\r\nlast"\r\nDB_HOST=10.44.0.8\r\n'
        values = guard.dotenv(text)
        self.assertEqual(values['PRIVATE'], 'first\nDB_HOST=127.0.0.1\nMAIL_HOST=localhost\nlast')
        self.assertEqual(values['DB_HOST'], '10.44.0.8')
        self.assertNotIn('MAIL_HOST', values)

    def test_phpdotenv_literal_name_and_value_forms_are_retained(self):
        self.assertEqual(guard.dotenv('123.name=value\nA=foo"bar\nB=first\'second\n'),
                         {'123.name': 'value', 'A': 'foo"bar', 'B': "first'second"})

    def test_malformed_or_unsupported_syntax_raises_only_a_safe_line_number(self):
        cases = (
            ('GOOD=value\nBROKEN=never-show-this more\n', 2),
            ('GOOD=value\nPRIVATE="never-show-this\\q"\n', 2),
            ('GOOD=value\nPRIVATE="never-show-this\nDB_HOST=localhost\n', 2),
            ("GOOD=value\nPRIVATE='never-show-this\n", 2),
            ('GOOD=value\nINVALID-NAME=never-show-this\n', 2),
            ('GOOD=value\n=never-show-this\n', 2),
            ('GOOD=value\nPRIVATE="never-show-this"trailing\n', 2),
            ('GOOD=value\nPRIVATE = "never-show-this\nnext"\n', 2),
        )
        for text, line in cases:
            with self.subTest(case=text):
                with self.assertRaises(guard.DotenvSyntaxError) as raised:
                    guard.dotenv(text)
                self.assertEqual(raised.exception.line, line)
                self.assertEqual(str(raised.exception), 'Malformed or unsupported dotenv syntax.')
                self.assertNotIn('never-show-this', repr(raised.exception))


if __name__ == '__main__':
    unittest.main()
