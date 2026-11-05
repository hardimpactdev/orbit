"""Disposable Vaultwarden service and credentials for the minimal topology only.

Old path: do not extend; target: the case runner (swamp/proof/runner) and cases files, docs/reference/verification-cases.mdx. Listed in docs/reference/old-paths.md.
"""

import json
import os
import secrets
import shutil
import ssl
import subprocess
import sys
import time
import urllib.request
from pathlib import Path

SOURCE = Path(__file__).resolve().parents[2]
STATE = Path("/home/orbit/.orbit/swamp-proof")
IMAGE = "vaultwarden/server@sha256:4fe17f415f90087377d37c755e0ea3cf189dcc6794e49cdd2a9adbb4937d9e1f"


def run(argv, **kwargs):
    return subprocess.run(argv, check=True, capture_output=True, timeout=300, **kwargs)


def main():
    # Only an initialized disposable proof VM has this state; never select a fleet server.
    up = json.loads((STATE / "up.json").read_text())
    target = up["target"]["key"]
    address = up["target"]["address"]
    folder = STATE / "vault-setup"
    folder.mkdir(mode=0o700, exist_ok=True)
    run(
        [
            "openssl",
            "req",
            "-newkey",
            "rsa:2048",
            "-nodes",
            "-keyout",
            str(folder / "key.pem"),
            "-out",
            str(folder / "leaf.csr"),
            "-subj",
            "/CN=vault-proof.orbit",
        ]
    )
    (folder / "leaf.ext").write_text(
        "basicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\nsubjectKeyIdentifier=hash\nauthorityKeyIdentifier=keyid,issuer\nsubjectAltName=DNS:vault-proof.orbit\n"
    )
    run(
        [
            "openssl",
            "x509",
            "-req",
            "-in",
            str(folder / "leaf.csr"),
            "-CA",
            str(STATE / "tls/ca.pem"),
            "-CAkey",
            str(STATE / "tls/ca-key.pem"),
            "-CAcreateserial",
            "-out",
            str(folder / "leaf.pem"),
            "-days",
            "1",
            "-extfile",
            str(folder / "leaf.ext"),
        ]
    )
    remote = "/tmp/orbit-vault-proof"
    run(["incus", "exec", target, "--", "mkdir", "-p", remote])
    for name in ("leaf.pem", "key.pem"):
        run(
            [
                "incus",
                "file",
                "push",
                "--mode",
                "0644",
                str(folder / name),
                target + remote + "/" + name,
            ]
        )
    run(["incus", "exec", target, "--", "apt-get", "install", "-y", "docker.io"])
    # The server and all its data die with this proof VM. Repeated proof runs replace only this fixture.
    subprocess.run(
        [
            "incus",
            "exec",
            target,
            "--",
            "docker",
            "rm",
            "--force",
            "--volumes",
            "orbit-vault-proof",
        ],
        check=False,
        capture_output=True,
        timeout=30,
    )
    run(
        [
            "incus",
            "exec",
            target,
            "--",
            "docker",
            "run",
            "--detach",
            "--name",
            "orbit-vault-proof",
            "--user",
            "65534:65534",
            "--cap-drop=ALL",
            "--read-only",
            "--tmpfs",
            "/data:rw,size=64m,uid=65534,gid=65534,mode=0700",
            "--mount",
            "type=bind,source=" + remote + ",target=/tls,readonly",
            "--publish",
            address + ":8443:8080",
            "--env",
            "ROCKET_PORT=8080",
            "--env",
            'ROCKET_TLS={certs="/tls/leaf.pem",key="/tls/key.pem"}',
            "--env",
            "SIGNUPS_ALLOWED=true",
            "--env",
            "SIGNUPS_VERIFY=false",
            IMAGE,
        ]
    )
    hosts = Path("/etc/hosts")
    hosts.write_text(
        "\n".join(
            line
            for line in hosts.read_text().splitlines()
            if "vault-proof.orbit" not in line
        )
        + "\n"
        + address
        + " vault-proof.orbit\n"
    )
    ca = "/usr/local/share/ca-certificates/orbit-root.crt"
    shutil.copyfile(STATE / "tls/ca.pem", ca)
    os.chmod(ca, 0o644)
    run(["apt-get", "update"])
    run(
        [
            "apt-get",
            "install",
            "-y",
            "--no-install-recommends",
            "python3-cryptography",
            "nodejs",
            "npm",
            "jq",
        ]
    )
    url = "https://vault-proof.orbit:8443"
    context = ssl.create_default_context(cafile=ca)
    deadline = time.monotonic() + 30
    while True:
        try:
            urllib.request.urlopen(url + "/alive", context=context, timeout=2).close()
            break
        except OSError:
            if time.monotonic() >= deadline:
                raise RuntimeError(
                    "Disposable Vaultwarden did not become ready"
                ) from None
            time.sleep(0.2)
    sys.path.insert(0, str(SOURCE / "tools/swamp-proof-guest"))
    from vaultwarden_account import provision

    configuration = provision(url, secrets.token_urlsafe(32), ca)
    if len(sys.argv) > 1 and sys.argv[1] in ("sync", "teardown", "keep"):
        if sys.argv[1] != "sync":
            with open(folder / "account.json", "x", opener=lambda path, flags: os.open(path, flags, 0o600)) as account:
                json.dump(configuration, account)
        # An external connection record needs no Database server in this topology.
        # Its private test credential enters PHP only through stdin.
        run(
            ["runuser", "-u", "orbit", "--", "php", "-r", "require 'vendor/autoload.php'; $app = require 'bootstrap/app.php'; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); $v = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR); App\\Models\\DatabaseConnection::query()->create(['slug'=>'vault-proof','driver'=>'mysql','host'=>'192.0.2.44','database'=>'proof','username'=>'proof','password'=>$v['password']]);"],
            cwd=SOURCE / "apps/gateway",
            input=json.dumps({"password": secrets.token_urlsafe(32)}).encode(),
        )
    # The proof driver binds these values only into request bodies, never its receipt.
    print(
        json.dumps(
            {
                "secrets": {
                    **configuration,
                    "organization": configuration["organization_id"],
                    "collection": configuration["collection_id"],
                }
            }
        )
    )


if __name__ == "__main__":
    main()
