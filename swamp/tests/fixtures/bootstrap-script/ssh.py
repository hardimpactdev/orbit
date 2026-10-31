#!/usr/bin/python3
"""Harmless binary seam for the UNMODIFIED official SSH extension."""
import os
import sys
from pathlib import Path

if '-O' in sys.argv or '-N' in sys.argv or '-MNf' in sys.argv:
    sys.exit(0)
command = sys.argv[-1]
if 'uname' in command:
    if Path(os.environ['FIXTURE_FAIL_PATH']).exists():
        sys.exit(1)
    print('Linux x86_64')
elif 'cat ' in command:
    print(os.environ['FIXTURE_PUBLIC_KEY'])
else:
    raise AssertionError('Unexpected official SSH command: ' + command)
