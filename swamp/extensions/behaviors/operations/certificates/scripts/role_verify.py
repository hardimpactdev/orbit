"""Stage and verify the public role signature against its Node-local key."""

import json
import os

from local import emit, valid_pair, write_signature
from role_paths import KEY_ROOT, role_paths


def verify(leaf):
    name, current, _ = role_paths(leaf)
    if valid_pair(
        current / (name + ".pem"),
        current / (name + ".key"),
        current / "root-ca.pem",
        leaf,
    ):
        return {"applied": True}
    workspace = KEY_ROOT / leaf["scope"] / leaf["version"]
    write_signature(
        workspace,
        leaf,
        json.loads(os.environ["ORBIT_CERTIFICATE_SIGNED"]),
        check_expiry=True,
    )
    return {"applied": True}


if __name__ == "__main__":
    emit(verify)
