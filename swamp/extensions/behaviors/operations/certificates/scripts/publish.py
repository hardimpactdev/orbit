"""Publish the public signature using only the key already on this Node."""

import base64
import grp
import hashlib
import json
import os
import shutil

from local import CADDY, account, current_link, directory, emit, openssl, valid


def publish(leaf):
    user, root, caddy = account(leaf)
    if valid(root, caddy, leaf):
        return {"applied": True}
    version = leaf["version"]
    candidate = root / "versions" / (version + ".candidate")
    published = root / "versions" / version
    # Resume a publication interrupted after the user pair was committed.
    source = published if published.is_dir() else candidate
    signed = json.loads(os.environ["ORBIT_CERTIFICATE_SIGNED"])
    certificate = base64.b64decode(signed["certificate_base64"], validate=True)
    ca = base64.b64decode(signed["root_certificate_base64"], validate=True)
    if hashlib.sha256(ca).hexdigest() != leaf["root_hash"]:
        raise ValueError("certificate.root_mismatch")
    for name, content in (("cert.pem", certificate), ("root.pem", ca)):
        path = source / name
        path.write_bytes(content)
        path.chmod(0o644)
        os.chown(path, user.pw_uid, user.pw_gid)
    openssl(
        "verify",
        "-CAfile",
        source / "root.pem",
        "-verify_hostname",
        leaf["domain"],
        source / "cert.pem",
    )
    if openssl("pkey", "-in", source / "key.pem", "-pubout") != openssl(
        "x509", "-in", source / "cert.pem", "-noout", "-pubkey"
    ):
        raise ValueError("certificate.key_mismatch")
    if source == candidate:
        candidate.rename(published)
    current_link(root, version)
    gid = grp.getgrnam("caddy").gr_gid
    for path in (CADDY, caddy, caddy / "versions", caddy / "versions" / version):
        directory(path, 0, gid, 0o750)
    for name, mode in (("cert.pem", 0o644), ("key.pem", 0o640)):
        path = caddy / "versions" / version / name
        shutil.copyfile(published / name, path)
        path.chmod(mode)
        os.chown(path, 0, gid)
    current_link(caddy, version)
    return {"applied": True}


if __name__ == "__main__":
    emit(publish)
