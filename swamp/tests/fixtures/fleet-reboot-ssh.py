#!/usr/bin/env python3
"""Local-only SSH fixture: fail selected connections, then run Ansible's module locally. Never opens a socket."""
import json
import os
import subprocess
import sys
from pathlib import Path

folder = Path(os.environ['REBOOT_SSH_FIXTURE'])
state = json.loads((folder / 'ssh.json').read_text())
state['calls'] += 1
state.setdefault('argv', []).append(sys.argv[1:])
(folder / 'ssh.json').write_text(json.dumps(state))
if state['calls'] <= state['failures']:
    # Ansible writes its pipelined module before reading the SSH diagnostic.
    # Drain it so this synthetic refusal cannot become a local BrokenPipeError.
    # A non-pipelined command has a terminal that Ansible keeps open until exit.
    while not sys.stdin.isatty() and sys.stdin.buffer.read(65536):
        pass
    print('ssh: connect to host 192.0.2.9 port 22: Connection timed out', file=sys.stderr)
    print('Authorization: Bearer fixture-private-credential', file=sys.stderr)
    sys.exit(255)
if state.get('disconnect_after'):
    result = subprocess.run(['/bin/sh', '-c', sys.argv[-1]], check=False)
    if state['disconnect_after'] in sys.argv[-1] and not state.get('dropped'):
        state['dropped'] = True
        (folder / 'ssh.json').write_text(json.dumps(state))
        print('ssh: connection reset after command executed', file=sys.stderr)
        sys.exit(255)
    sys.exit(result.returncode)
os.execv('/bin/sh', ['/bin/sh', '-c', sys.argv[-1]])
