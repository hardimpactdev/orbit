"""Execute one admitted Project command; private program and output stay off native history."""
import hashlib
import json
import os
import sys
from pathlib import Path

value = json.load(sys.stdin)
command = value['command']
if hashlib.sha256(command.encode()).hexdigest() != value['sha256']:
    raise SystemExit(1)
directory = Path(value['directory'])
if directory.is_symlink() or not directory.is_dir() or directory.stat().st_uid != os.geteuid():
    raise SystemExit(1)
os.chdir(directory)
null = os.open(os.devnull, os.O_WRONLY)
os.dup2(null, 1)
os.dup2(null, 2)
environment = dict(os.environ, ORBIT_INSTANCE_ID=str(value['instance_id']), ORBIT_INSTANCE_NAME=value['instance_name'])
os.execve('/bin/sh', ['sh', '-c', command], environment)
