"""Install the admitted public root in this Node's operating-system trust."""

import os
import shutil
import subprocess

from local import ANCHOR, account, emit, fingerprint


def trust(leaf):
    _, root, _ = account(leaf)
    ca = root / "current/root.pem"
    if fingerprint(ca) != leaf["root_hash"]:
        raise ValueError("certificate.root_mismatch")
    if not ANCHOR.exists() or fingerprint(ANCHOR) != leaf["root_hash"]:
        staged = ANCHOR.with_name(".orbit-root-" + str(os.getpid()))
        shutil.copyfile(ca, staged)
        staged.chmod(0o644)
        os.chown(staged, 0, 0)
        staged.replace(ANCHOR)
        subprocess.run(
            ["update-ca-certificates"],
            check=True,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
        )
    return {"applied": True}


if __name__ == "__main__":
    emit(trust)
