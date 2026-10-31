"""Return the public presence result of the admitted leaf or removed scope."""

import os

from local import account, emit, valid


def inspect(scope):
    _, root, caddy = account(scope)
    present = (
        valid(root, caddy, scope)
        if "domain" in scope
        else root.exists() or caddy.exists()
    )
    if present != (os.environ["ORBIT_CERTIFICATE_EXPECT_PRESENT"] == "true"):
        raise ValueError("certificate.presence_mismatch")
    return {"present": present}


if __name__ == "__main__":
    emit(inspect)
