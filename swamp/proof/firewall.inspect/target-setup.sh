#!/usr/bin/env bash
set -euo pipefail
: "${SUDO_USER:?Run through the target user with sudo -n}"
# These effects are confined to this topology workload VM.
ufw allow 22/tcp comment proof-ssh
ufw --force enable
python3 - /tmp/swamp-proof-firewall-before.json "$SUDO_USER" <<'PY'
import hashlib,json,pathlib,pwd,sys,os
state={name:hashlib.sha256(pathlib.Path(name).read_bytes()).hexdigest() for name in ['/etc/ufw/user.rules','/etc/ufw/user6.rules']}
path=pathlib.Path(sys.argv[1]);path.write_text(json.dumps(state))
user=pwd.getpwnam(sys.argv[2]);os.chown(path,user.pw_uid,user.pw_gid);path.chmod(0o600)
PY
