"""The real release runner loaded the staged commit and wrote the exact grants, with production permissions."""
import json
import os
import pwd
import stat
import subprocess
from pathlib import Path

source = Path.cwd()
operation = json.loads(Path(os.environ['PROOF_OPERATION_FILE']).read_text())
loaded = json.loads(Path('/home/orbit/.orbit/swamp-proof/gateway-load.json').read_text())
if loaded['outcome'] != 'loaded':
    print(Path(os.environ['PROOF_OUTPUTS']).read_text()[-6000:])
assert loaded['load_seconds'] <= 600
assert loaded['outcome'] == 'loaded' and loaded['operation_id'] == operation['id']
assert operation['result']['sha'] == subprocess.check_output(['runuser', '-u', 'orbit', '--', 'git', 'rev-parse', 'HEAD'], text=True).strip()
expected = json.loads(subprocess.check_output(['php', 'apps/gateway/bin/render-swamp-grants', 'swamp'], text=True))
account = pwd.getpwnam('orbit')
for name, content in expected.items():
    path = source / 'swamp' / name
    assert path.read_text() == content, name
    info = path.stat()
    assert stat.S_IMODE(info.st_mode) == 0o640 and info.st_uid == account.pw_uid and info.st_gid == account.pw_gid, name
assert operation['input']['grants'] == expected
assert operation['result']['workflows'] > 0
print(json.dumps({'release_runner': 'loaded', 'load_seconds': round(loaded['load_seconds'], 3), 'grant_files': len(expected), 'owner': 'orbit:orbit', 'mode': '0640'}))
