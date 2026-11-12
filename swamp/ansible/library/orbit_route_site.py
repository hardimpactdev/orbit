#!/usr/bin/python
"""Batch a Route Node's certificate and Caddy files without transporting private keys."""

import grp
import os
import pwd
import tempfile
from pathlib import Path

DOCUMENTATION = r"""
module: orbit_route_site
short_description: Converge admitted Route certificate and Caddy files on one Node
version_added: "1.0.0"
description: Keep private keys on their Node, publish file metadata, then validate and reload Caddy.
options:
  intent:
    description: Gateway-admitted public Route intent.
    type: dict
    required: true
  node_id:
    description: The Node whose files to converge.
    type: int
    required: true
  signed:
    description: Public signed certificate replies in leaf-index order.
    type: list
    elements: str
    required: true
author: Orbit
"""


def converge(
    intent,
    node_id,
    signed,
    run,
    root=Path("/"),
    account_lookup=pwd.getpwnam,
    group_lookup=grp.getgrnam,
):
    account = account_lookup(intent["nodes"][str(node_id)]["account"])
    changed = False

    def path(value):
        return root / str(value).lstrip("/")

    def selected(key):
        return [
            row for row in intent.get(key, []) if str(row["node_id"]) == str(node_id)
        ]

    def file(value, content, uid, gid, mode):
        nonlocal changed
        dest = path(value)
        data = content.encode()
        if not dest.exists() or dest.read_bytes() != data:
            fd, temporary = tempfile.mkstemp(dir=dest.parent)
            try:
                with os.fdopen(fd, "wb") as stream:
                    os.fchown(stream.fileno(), uid, gid)
                    os.fchmod(stream.fileno(), mode)
                    stream.write(data)
                os.replace(temporary, dest)
            finally:
                Path(temporary).unlink(missing_ok=True)
            changed = True
            return True
        current = dest.stat()
        drift = (current.st_uid, current.st_gid, current.st_mode & 0o7777) != (
            uid,
            gid,
            mode,
        )
        if drift:
            os.chown(dest, uid, gid)
            os.chmod(dest, mode)
            changed = True
        return drift

    def link(value, target, uid):
        nonlocal changed
        dest = path(value)
        if not dest.is_symlink() or os.readlink(dest) != target:
            if dest.is_dir() and not dest.is_symlink():
                dest.rmdir()  # Never remove a nonempty foreign directory.
            fd, name = tempfile.mkstemp(prefix=".orbit-route-link-", dir=dest.parent)
            os.close(fd)
            temporary = Path(name)
            temporary.unlink()
            try:
                temporary.symlink_to(target)
                os.replace(temporary, dest)
            finally:
                temporary.unlink(missing_ok=True)
            changed = True
        if dest.lstat().st_uid != uid:
            os.lchown(dest, uid, -1)
            changed = True

    for index in intent.get("leaf_indexes", []):
        leaf = intent["leaves"][int(index)]
        if str(leaf["node_id"]) != str(node_id):
            continue
        begin = "-----BEGIN CERTIFICATE-----"
        # Match the existing signed-response extraction exactly.
        certificate = begin + signed[int(index)].split(begin)[1]
        file(
            leaf["directory"] + "/cert.pem",
            certificate,
            0,
            group_lookup("caddy").gr_gid,
            0o644,
        )
        link(leaf["current"], leaf["target"], 0)
        destination = str(
            path(
                account.pw_dir
                + "/.orbit/certificates/"
                + leaf["scope"]
                + "/"
                + leaf["target"]
            )
        )
        # install copies the private key locally. Neither its bytes nor tool output enters the module reply.
        run(
            [
                "timeout",
                "30",
                "install",
                "-D",
                "-o",
                leaf["user"],
                "-g",
                str(account.pw_gid),
                "-m",
                "0600",
                "-t",
                destination,
                str(path(leaf["directory"] + "/key.pem")),
                str(path(leaf["directory"] + "/cert.pem")),
            ]
        )
        changed = True  # Preserve the existing account-copy/reload behavior on repeated publication.
        owner = account_lookup(leaf["user"])
        link(
            account.pw_dir + "/.orbit/certificates/" + leaf["scope"] + "/current",
            leaf["target"],
            owner.pw_uid,
        )
    if str(node_id) in {str(value) for value in intent.get("trust", [])} and file(
        "/usr/local/share/ca-certificates/orbit-managed-root-ca.crt",
        intent["root_certificate"],
        0,
        0,
        0o644,
    ):
        # Complete this before any later failure, as the former flush_handlers did.
        run(["timeout", "60", "update-ca-certificates"])
    sites = selected("caddy")
    if sites:
        caddy_gid = group_lookup("caddy").gr_gid
        for site in sites:
            file("/etc/caddy/Caddyfile", site["content"], 0, caddy_gid, 0o640)
        run(
            [
                "timeout",
                "60",
                "runuser",
                "-u",
                "caddy",
                "--",
                "caddy",
                "validate",
                "--config",
                str(path("/etc/caddy/Caddyfile")),
                "--adapter",
                "caddyfile",
            ]
        )
        run(["timeout", "60", "systemctl", "reload", "caddy"])
        changed = True
    for marker in selected("awake"):
        file(marker["path"], "", 0, 0, 0o644)
    return {"changed": changed}


def main():
    from ansible.module_utils.basic import AnsibleModule

    module = AnsibleModule(
        argument_spec={
            "intent": {"type": "dict", "required": True},
            "node_id": {"type": "int", "required": True},
            "signed": {"type": "list", "elements": "str", "required": True},
        },
        supports_check_mode=False,
    )

    def run(argv):
        rc, _, _ = module.run_command(argv)
        if rc:
            raise RuntimeError(
                "Route publication command failed: "
                + (
                    "Caddy validation"
                    if "validate" in argv
                    else "Caddy reload"
                    if "reload" in argv
                    else "certificate installation"
                )
                + "."
            )

    try:
        module.exit_json(
            **converge(
                module.params["intent"],
                module.params["node_id"],
                module.params["signed"],
                run,
            )
        )
    except Exception:  # noqa: BLE001 - never expose private key or arbitrary tool output.
        module.fail_json(msg="Route certificate or site publication failed.")


if __name__ == "__main__":
    main()
