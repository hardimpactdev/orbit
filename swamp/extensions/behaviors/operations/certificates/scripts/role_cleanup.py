"""Remove this admission's pending key only after successful native activation."""

import shutil

from local import emit, valid_pair
from role_paths import role_paths, role_workspace


def cleanup(leaf):
    certificate, key, root = role_paths(leaf)
    if not valid_pair(
        certificate,
        key,
        root,
        leaf,
    ):
        raise ValueError("certificate.role_pair_invalid")
    workspace = role_workspace(leaf)
    if workspace.exists():
        shutil.rmtree(workspace)
    return {"applied": True}


if __name__ == "__main__":
    emit(cleanup)
