#!/usr/bin/env python3
"""An Incus ACL boundary for the isolated bridge-writer Ansible proof; opens no socket."""
import json
import os
import sys
from pathlib import Path

root = Path(os.environ['ORBIT_BRIDGE_FIXTURE'])
path = root / 'acl.json'
state = json.loads(path.read_text())
if '--request' in sys.argv:
    value = json.load(sys.stdin)
    before = {row['destination'] for row in state['metadata']['egress']}
    after = {row['destination'] for row in value['egress']}
    if (root / 'timeout-remove-b').exists() and '10.44.0.8/32' in before - after:
        # No delivery. The writer's subsequent GET must observe A gone and B still present.
        raise SystemExit(28)
    state = {'metadata': value, 'version': state['version'] + 1}
    path.write_text(json.dumps(state))
sys.stdout.buffer.write(('HTTP/1.1 200 OK\r\nETag: ' + str(state['version']) + '\r\n\r\n'
                         + json.dumps({'metadata': state['metadata']})).encode())
