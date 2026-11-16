"""The one rule that names the venv of the shape gate's pinned tools (docs/reference/implementation-loop.md#run-the-swamp-shape-gate-before-you-push).

A venv runs only with the interpreter that built it. A venv of another interpreter can hold `.complete` and still fail, for example
with `cannot load libpython3.13`. So the name covers the pins and the interpreter that runs this code: its version and its real path.

bin/swamp-shape-gate builds the venv with the interpreter that runs it, and names it with `venv_name`. The task VM role runs this file
on the VM with the python3 that builds the venv there: `python3 -I -c <this file>`, the pins on standard input, the name on standard
output.
"""
import hashlib
import os
import platform
import sys


def venv_name(pins):
    """The venv folder of these pins, built by this interpreter."""
    interpreter = f'{platform.python_version()}\n{os.path.realpath(sys.executable)}\n'
    return f'venv-{hashlib.sha256((pins + interpreter).encode()).hexdigest()[:16]}'


if __name__ == '__main__':
    print(venv_name(sys.stdin.read()))
