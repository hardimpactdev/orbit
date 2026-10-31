#!/usr/bin/python3
"""Run one trusted host invocation only while its private start lease exists."""
import os
from pathlib import Path
import stat
import sys

lease = Path(sys.argv[1])
try:
    metadata = lease.lstat()
    allowed = stat.S_ISREG(metadata.st_mode) and metadata.st_uid == os.getuid() and metadata.st_mode & 0o077 == 0
except OSError:
    allowed = False
if not allowed or os.getuid() == 0:
    sys.exit(78)
os.execve(sys.argv[2], sys.argv[2:], dict(os.environ))
