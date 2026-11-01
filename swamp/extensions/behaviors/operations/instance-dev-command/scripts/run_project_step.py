"""Execute an admitted Project command and retain bounded, redacted output tails."""
import hashlib
import json
import os
import re
import selectors
import stat
import subprocess
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
environment = dict(os.environ, ORBIT_INSTANCE_ID=str(value['instance_id']), ORBIT_INSTANCE_NAME=value['instance_name'], ORBIT_SEED_PATH=value['seed_path'], ORBIT_SEED_COMMIT=value['seed_commit'])
redactions = {command}
for key, item in environment.items():
    if re.search(r'TOKEN|SECRET|PASSWORD|CREDENTIAL|(?:^|_)KEY$', key, re.IGNORECASE) and item:
        redactions.add(item)
# Only collect strings for redaction. This does not load the Node file into the command's environment.
try:
    descriptor = os.open('.env', os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
except FileNotFoundError:
    pass
else:
    with os.fdopen(descriptor, 'r', encoding='utf-8') as stream:
        info = os.fstat(stream.fileno())
        if not stat.S_ISREG(info.st_mode) or info.st_uid != os.geteuid() or info.st_size > 1048576:
            raise SystemExit(1)
        for line in stream.read().splitlines():
            if '=' not in line or line.lstrip().startswith('#'):
                continue
            item = line.split('=', 1)[1].strip()
            redactions.add(item)
            if len(item) >= 2 and item[0] in '\'"' and item[-1] == item[0]:
                item = item[1:-1]
            # The canonical writer escapes these five characters in quoted values.
            item = re.sub(r'\\([nr\\"$])', lambda match: {'n': '\n', 'r': '\r', '\\': '\\', '"': '"', '$': '$'}[match[1]], item)
            redactions.update([item, *item.splitlines()])
redactions.discard('')
secrets = sorted((item.encode() for item in redactions), key=len, reverse=True)
limit = 4096 + max(map(len, secrets), default=0)
tails = {'stdout': bytearray(), 'stderr': bytearray()}
process = subprocess.Popen(['/bin/bash', '-eu', '-c', command], env=environment,
                           stdout=subprocess.PIPE, stderr=subprocess.PIPE)
with selectors.DefaultSelector() as streams:
    streams.register(process.stdout, selectors.EVENT_READ, 'stdout')
    streams.register(process.stderr, selectors.EVENT_READ, 'stderr')
    while streams.get_map():
        for key, _event in streams.select():
            chunk = os.read(key.fd, 65536)
            if not chunk:
                streams.unregister(key.fileobj)
                key.fileobj.close()
                continue
            tail = tails[key.data]
            tail.extend(chunk)
            if len(tail) > limit:
                del tail[:-limit]
code = process.wait()
output = {'exitCode': code}
for name, tail in tails.items():
    text = bytes(tail)
    for secret in secrets:
        text = text.replace(secret, b'[REDACTED]')
    output[name] = text[-4096:].decode('utf-8', errors='ignore')
print(json.dumps(output))
sys.exit(code)
