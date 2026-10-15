"""The runtime contract of a Python remote program: the bootstrap, one JSON result line, and named outcomes."""
import json
import signal
import subprocess
import unittest

from harness import REMOTE, execute_source, with_newline

RUNTIME = with_newline((REMOTE / 'lib/program.py').read_text())


def run(body, payload=b'{}', **options):
    return execute_source('tests.program', RUNTIME + body, payload, **options)


class ProgramRuntimeTest(unittest.TestCase):
    def test_a_returned_outcome_is_one_json_line(self):
        completed = run("run_program(lambda payload: ('done', {'echo': payload['value']}))\n", b'{"value": "a\\u00e9"}')

        self.assertEqual(completed.returncode, 0, completed.stderr)
        self.assertEqual(completed.stdout, b'{"outcome":"done","data":{"echo":"a\\u00e9"}}\n')

    def test_a_refusal_is_an_outcome_and_its_check_goes_to_the_log(self):
        completed = run(
            "def main(payload):\n"
            "    require(False, 'path_mismatch', 'the checkout is inside the apps root')\n"
            "run_program(main)\n",
        )

        self.assertEqual(completed.returncode, 0)
        self.assertEqual(json.loads(completed.stdout), {'outcome': 'path_mismatch', 'data': {}})
        self.assertEqual(completed.stderr, b'path_mismatch: the checkout is inside the apps root\n')

    def test_input_that_is_not_an_object_ends_without_a_result(self):
        completed = run("run_program(lambda payload: ('done', {}))\n", b'[]')

        self.assertNotEqual(completed.returncode, 0)
        self.assertEqual(completed.stdout, b'')

    def test_an_exception_ends_without_a_result_and_names_the_program(self):
        completed = run("def main(payload):\n    raise RuntimeError('boom')\nrun_program(main)\n")

        self.assertNotEqual(completed.returncode, 0)
        self.assertEqual(completed.stdout, b'')
        self.assertIn(b'File "tests.program"', completed.stderr)

    def test_the_program_does_not_import_from_the_working_directory(self):
        completed = run(
            "import json\nrun_program(lambda payload: ('done', {'json': json.__file__}))\n",
            cwd=str(REMOTE / 'lib'),
        )

        self.assertNotIn(str(REMOTE), json.loads(completed.stdout)['data']['json'])

    def test_sigterm_ends_the_program_without_a_result(self):
        process = subprocess.Popen(
            ['python3', '-I', '-c', (REMOTE / 'bootstrap.py').read_text(), 'tests.program'],
            stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
        )
        body = "import sys, time\ndef main(payload):\n    print('ready', file=sys.stderr, flush=True)\n    time.sleep(30)\nrun_program(main)\n"
        process.stdin.write((RUNTIME + body).encode() + b'\0{}')
        process.stdin.close()
        self.assertEqual(process.stderr.readline(), b'ready\n')
        process.send_signal(signal.SIGTERM)

        self.assertEqual(process.wait(timeout=10), 128 + signal.SIGTERM)
        self.assertEqual(process.stdout.read(), b'')
        process.stdout.close()
        process.stderr.close()


if __name__ == '__main__':
    unittest.main()
