#!/usr/bin/python3
"""Harmless SSH fixture: assert private file custody without printing the key."""
import hashlib
import os
import stat
import sys
from pathlib import Path

assert 'ORBIT_BOOTSTRAP_IDENTITY' not in os.environ
assert sys.argv[-2:] == ['candidate@dev.example', '/usr/bin/uname -sm']
assert 'StrictHostKeyChecking=yes' in sys.argv
key = Path(sys.argv[sys.argv.index('-i') + 1])
assert stat.S_IMODE(key.stat().st_mode) == 0o600
assert hashlib.sha256(key.read_bytes()).hexdigest() == os.environ['FIXTURE_KEY_SHA256']
print('Linux x86_64')
