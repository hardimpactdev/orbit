#!/usr/bin/env bash
set -euo pipefail
: "${SUDO_USER:?Run through the target user with sudo -n}"
: "${PROOF_KIND_DIR:?Runner must stage the kind directory}"
# These effects are confined to the disposable target container.
ufw allow 22/tcp comment proof-ssh
ufw allow proto tcp from 192.0.2.1 to any port 443 comment proof-owned
ufw --force enable
python3 - "$PROOF_KIND_DIR/before.json" "$SUDO_USER" <<'PY'
import hashlib,json,pathlib,pwd,sys,os
state={name:hashlib.sha256(pathlib.Path(name).read_bytes()).hexdigest() for name in ['/etc/ufw/user.rules','/etc/ufw/user6.rules']}
path=pathlib.Path(sys.argv[1]);path.write_text(json.dumps(state))
user=pwd.getpwnam(sys.argv[2]);os.chown(path,user.pw_uid,user.pw_gid);path.chmod(0o600)
PY
