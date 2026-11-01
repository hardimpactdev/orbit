"""Request one fixed role's public CSR; its private key stays on this Node."""

from local import directory, emit, request_for_key, valid_pair
from role_paths import KEY_ROOT, role_owner, role_paths, role_workspace


def request(leaf):
    certificate, key, root = role_paths(leaf)
    if valid_pair(
        certificate,
        key,
        root,
        leaf,
    ):
        return {"state": "current"}
    workspace = role_workspace(leaf)
    if leaf["scope"] == "role-swamp":
        uid, gid, mode, _ = role_owner(leaf)
        directory(certificate.parent, uid, gid, mode)
    else:
        directory(KEY_ROOT, 0, 0, 0o700)
    for path in (workspace.parent, workspace):
        directory(path, 0, 0, 0o700)
    return request_for_key(workspace / "key.pem", leaf["domain"])


if __name__ == "__main__":
    emit(request)
