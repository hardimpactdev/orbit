"""Remove this admission's pending key only after successful native activation."""

import shutil

from local import emit, valid_pair
from role_paths import KEY_ROOT, role_paths


def cleanup(leaf):
    role, current, _ = role_paths(leaf)
    if not valid_pair(
        current / (role + ".pem"),
        current / (role + ".key"),
        current / "root-ca.pem",
        leaf,
    ):
        raise ValueError("certificate.role_pair_invalid")
    workspace = KEY_ROOT / leaf["scope"] / leaf["version"]
    if workspace.exists():
        shutil.rmtree(workspace)
    return {"applied": True}


if __name__ == "__main__":
    emit(cleanup)
