"""Run one unittest fixture file as `__main__` and record its first failure at once.

A unit that the runner cuts at its time limit does not gate, unless an assertion failed before the cut. unittest
prints a failure's details only when the whole run ends, so the runner cannot read them from a unit that hangs. This
writes a marker file the moment a test, a subtest or a class or module fixture fails.
"""
import os
import runpy
import sys
import unittest
from pathlib import Path

MARKER = os.environ.get('ORBIT_TEST_FAILURE_FILE')


def record(method):
    original = getattr(unittest.TestResult, method)

    def recording(self, *args, **kwargs):
        # addSubTest reports a passed subtest with no error.
        if MARKER and (method != 'addSubTest' or args[2] is not None):
            Path(MARKER).write_text(method)
        return original(self, *args, **kwargs)
    setattr(unittest.TestResult, method, recording)


for name in ('addFailure', 'addError', 'addUnexpectedSuccess', 'addSubTest'):
    record(name)

file = sys.argv[1]
sys.argv = sys.argv[1:]
# `python3 file` puts the file's folder first on the path, unless Python runs isolated.
if not sys.flags.isolated:
    sys.path[0] = str(Path(file).resolve().parent)
runpy.run_path(file, run_name='__main__')
