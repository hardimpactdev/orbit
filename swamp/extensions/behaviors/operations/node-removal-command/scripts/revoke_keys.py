"""Remove only admitted management keys and retain all other authorized keys."""

import json
import os
import pwd
import shlex
import tempfile
from pathlib import Path

REMOVAL_INPUT = None


def revoke(data):
    account = pwd.getpwnam(data["user"])
    target = Path(account.pw_dir) / ".ssh" / "authorized_keys"
    if not target.exists():
        return {"revoked": True}
    if target.is_symlink():
        raise ValueError("node.ssh_keys_invalid")
    keys = {tuple(key.split()[:2]) for key in data["keys"]}
    prior = target.read_bytes()
    kept = []
    for line in prior.splitlines(keepends=True):
        try:
            words = shlex.split(line.decode(), comments=False)
        except (ValueError, UnicodeDecodeError):
            kept.append(line)
            continue
        # An authorized_keys record has either a key or one options field
        # before the key. Everything after its base64 value is a comment.
        index = 0 if words and words[0].startswith(("ssh-", "ecdsa-", "sk-")) else 1
        if tuple(words[index:index + 2]) not in keys:
            kept.append(line)
    content = b"".join(kept)
    if content != prior:
        fd, name = tempfile.mkstemp(prefix=".orbit-revoke-", dir=target.parent)
        try:
            with os.fdopen(fd, "wb") as stream:
                os.fchmod(stream.fileno(), 0o600)
                os.fchown(stream.fileno(), account.pw_uid, account.pw_gid)
                stream.write(content)
                stream.flush()
                os.fsync(stream.fileno())
            os.replace(name, target)
        finally:
            Path(name).unlink(missing_ok=True)
    return {"revoked": True}


if __name__ == "__main__":
    print(json.dumps(revoke(REMOVAL_INPUT)))
