"""Stage one source template privately for an official SSH copy; print no contents."""
import json
import os
import sys
from pathlib import Path

value = json.load(sys.stdin)
source = Path(value['application']) / '.env.example'
destination = Path(value['destination'])
if source.is_symlink() or source.exists() and (not source.is_file() or source.stat().st_size > 131072):
    raise SystemExit(1)
destination.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
descriptor = os.open(destination, os.O_WRONLY | os.O_CREAT | os.O_TRUNC | os.O_NOFOLLOW, 0o600)
with os.fdopen(descriptor, 'wb') as output:
    output.write(source.read_bytes() if source.exists() else b'')
print('{"ready":true}')
