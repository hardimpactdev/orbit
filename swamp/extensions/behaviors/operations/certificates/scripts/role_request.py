"""Request one fixed role's public CSR; its private key stays on this Node."""

from local import directory, emit, request_for_key, valid_pair
from role_paths import KEY_ROOT, role_paths


def request(leaf):
    role, current, _ = role_paths(leaf)
    if valid_pair(
        current / (role + ".pem"),
        current / (role + ".key"),
        current / "root-ca.pem",
        leaf,
    ):
        return {"state": "current"}
    workspace = KEY_ROOT / leaf["scope"] / leaf["version"]
    for path in (KEY_ROOT, workspace.parent, workspace):
        directory(path, 0, 0, 0o700)
    return request_for_key(workspace / "key.pem", leaf["domain"])


if __name__ == "__main__":
    emit(request)
