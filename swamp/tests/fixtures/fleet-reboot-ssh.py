#!/usr/bin/env python3
"""Local-only SSH fixture: fail selected connections, then run Ansible's module locally. Never opens a socket."""
import json
import os
import sys
from pathlib import Path

folder = Path(os.environ['REBOOT_SSH_FIXTURE'])
state = json.loads((folder / 'ssh.json').read_text())
state['calls'] += 1
state.setdefault('argv', []).append(sys.argv[1:])
(folder / 'ssh.json').write_text(json.dumps(state))
if state['calls'] <= state['failures']:
    print('ssh: connect to host 192.0.2.9 port 22: Connection timed out', file=sys.stderr)
    print('Authorization: Bearer fixture-private-credential', file=sys.stderr)
    sys.exit(255)
os.execv('/bin/sh', ['/bin/sh', '-c', sys.argv[-1]])
