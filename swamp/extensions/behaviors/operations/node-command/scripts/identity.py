import json
import os
import pwd
import socket
import subprocess

ready = subprocess.check_output(['/usr/bin/sudo', '-n', '/usr/bin/id', '-u'], text=True).strip() == '0'
if not ready or pwd.getpwuid(os.getuid()).pw_name != 'orbit':
    raise SystemExit('Proxy sudo proof failed')
print(json.dumps({'node_id': int(os.environ['ORBIT_EFFECT_NODE_ID']), 'user': 'orbit', 'hostname': socket.gethostname(), 'effective_user': pwd.getpwuid(os.getuid()).pw_name, 'sudo_ready': ready}))
