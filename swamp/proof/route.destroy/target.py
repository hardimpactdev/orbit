"""Fault injection and verification inside the disposable app-dev workload VM."""

import json
import os
import pathlib
import pwd
import re
import subprocess
import sys

RECORD = pathlib.Path("/tmp/orbit-certificate-home-proof.json")
CADDY = pathlib.Path("/etc/caddy/orbit-certificates")


def inject():
    user = pwd.getpwnam(os.environ["SUDO_USER"])
    assert user.pw_uid != 0
    assert user.pw_dir.startswith("/")
    assert all(part not in ("", ".", "..") for part in user.pw_dir[1:].split("/"))
    home = pathlib.Path(user.pw_dir)
    scopes = [
        path
        for path in CADDY.glob("route-*")
        if re.fullmatch(r"route-[1-9][0-9]*", path.name)
    ]
    assert len(scopes) == 1, (
        "Proof requires only the Route created by prepare on this target"
    )
    owned = scopes[0]
    assert owned.is_dir() and not owned.is_symlink()
    assert (home / ".orbit/certificates" / owned.name / "current/key.pem").is_file()
    assert (owned / "current/cert.pem").is_file()
    unrelated = "route-" + str(int(owned.name.removeprefix("route-")) + 1000000000)
    assert not (CADDY / unrelated).exists()
    for scope in (owned.name, unrelated):
        local = home / ".orbit/certificates" / scope
        local.mkdir(mode=0o700, parents=True, exist_ok=True)
        os.chown(local, user.pw_uid, user.pw_gid)
        public = CADDY / scope
        public.mkdir(mode=0o755, exist_ok=True)
        for root in (local, public):
            (root / "proof-marker").write_text("public-proof-marker\n")
    RECORD.write_text(
        json.dumps(
            {
                "user": user.pw_name,
                "home": user.pw_dir,
                "owned_scope": owned.name,
                "unrelated_scope": unrelated,
            }
        )
    )
    subprocess.run(
        ["usermod", "--home", user.pw_dir + "/.", user.pw_name], check=True, timeout=10
    )
    return {"fault_injected": True}


def verify():
    record = json.loads(RECORD.read_text())
    user = pwd.getpwnam(record["user"])
    assert user.pw_uid != 0
    assert user.pw_dir == record["home"] + "/."
    try:
        home = pathlib.Path(user.pw_dir)
        for scope in (record["owned_scope"], record["unrelated_scope"]):
            for root in (home / ".orbit/certificates", CADDY):
                assert (
                    root / scope / "proof-marker"
                ).read_text() == "public-proof-marker\n"
        return {
            "noncanonical-nss-home-present": True,
            "certificate-scope-preserved": True,
            "unrelated-scope-preserved": True,
        }
    finally:
        subprocess.run(
            ["usermod", "--home", record["home"], user.pw_name], check=True, timeout=10
        )


if __name__ == "__main__":
    action = sys.argv[1]
    assert action in ("inject", "verify")
    print(json.dumps(inject() if action == "inject" else verify()))
