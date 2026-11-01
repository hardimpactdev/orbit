"""Stage and verify the public role signature against its Node-local key."""

import json
import os

from local import emit, valid_pair, write_signature
from role_paths import role_paths, role_workspace


def verify(leaf):
    certificate, key, root = role_paths(leaf)
    if valid_pair(
        certificate,
        key,
        root,
        leaf,
    ):
        return {"applied": True}
    workspace = role_workspace(leaf)
    write_signature(
        workspace,
        leaf,
        json.loads(os.environ["ORBIT_CERTIFICATE_SIGNED"]),
        check_expiry=True,
    )
    return {"applied": True}


if __name__ == "__main__":
    emit(verify)
