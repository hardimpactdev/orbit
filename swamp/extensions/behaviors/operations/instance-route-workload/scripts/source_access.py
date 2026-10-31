"""Grant Caddy access to the captured served roots; keep checkout private."""

import json
import os
import pwd
import subprocess
from pathlib import Path

WORKLOAD_INPUT = None


def command(argv, user=None):
    if user is not None:
        argv = ["/usr/sbin/runuser", "-u", user, "--", *argv]
    subprocess.run(argv, check=True, stdout=subprocess.DEVNULL)


def directory(path):
    if not path.is_dir() or path.is_symlink():
        raise ValueError("app-dev.source_access_failed")


def passage_grant(acl, user):
    fields = dict(
        line.rsplit(":", 1)
        for line in acl.splitlines()
        if line.count(":") == 2 and not line.startswith("default:")
    )
    permissions = fields.get("user:" + user, "---")
    mask = fields.get("mask:", fields.get("group:", "---"))
    return "u:" + user + ":" + permissions[:2] + "x,m::" + mask[:2] + "x"


def passage(path):
    directory(path)
    acl = subprocess.run(
        ["/usr/bin/getfacl", "-cp", "--", str(path)],
        check=True,
        capture_output=True,
        text=True,
    ).stdout
    command(
        ["/usr/bin/setfacl", "-n", "-m", passage_grant(acl, "caddy"), "--", str(path)]
    )


def grant(value):
    account = pwd.getpwnam(value["user"])
    sites = []
    for captured in value["sites"]:
        site = dict(captured)
        checkout = Path(site["checkout"])
        application = Path(site["application"])
        if checkout.is_symlink():
            if checkout.name != "current":
                raise ValueError("app-dev.source_access_failed")
            resolved = checkout.resolve(strict=True)
            if not resolved.is_relative_to(checkout.parent / "releases"):
                raise ValueError("app-dev.source_access_failed")
            application = resolved / application.relative_to(checkout)
            checkout = resolved
            site.update(checkout=str(checkout), application=str(application))
        root = checkout / site["relative_root"]
        for path in (checkout, root, application):
            directory(path)
        storage = application / "storage/app/public"
        linked_storage = False
        for parent, dirs, files in os.walk(root, followlinks=False):
            for name in [*dirs, *files]:
                path = Path(parent) / name
                if not path.is_symlink():
                    continue
                if not (
                    root == application / "public"
                    and path == root / "storage"
                    and storage.is_dir()
                    and not storage.is_symlink()
                    and path.resolve() == storage.resolve()
                ):
                    raise ValueError("app-dev.source_access_failed")
                linked_storage = True
        if linked_storage:
            for parent, dirs, files in os.walk(storage, followlinks=False):
                if any((Path(parent) / name).is_symlink() for name in [*dirs, *files]):
                    raise ValueError("app-dev.source_access_failed")
        site["storage"] = str(storage) if linked_storage else None
        sites.append(site)
    checkouts = sorted({site["checkout"] for site in sites})
    for checkout in checkouts:
        command(
            [
                "/usr/bin/find",
                "-P",
                checkout,
                "-type",
                "d",
                "-exec",
                "/usr/bin/setfacl",
                "-m",
                "d:u:caddy:---",
                "--",
                "{}",
                "+",
            ],
            account.pw_name,
        )
        command(
            ["/usr/bin/setfacl", "-P", "-R", "-n", "-m", "u:caddy:---", "--", checkout],
            account.pw_name,
        )
    for site in sites:
        root = Path(site["checkout"]) / site["relative_root"]
        for ancestor in root.parents:
            if ancestor == Path("/"):
                break
            passage(ancestor)
        storage = Path(site["storage"]) if site["storage"] else None
        if storage is not None:
            passage(storage.parent.parent)
            passage(storage.parent)
        for served in [root, *([storage] if storage is not None else [])]:
            command(
                [
                    "/usr/bin/find",
                    "-P",
                    str(served),
                    "-type",
                    "d",
                    "-exec",
                    "/usr/bin/setfacl",
                    "-m",
                    "d:u:caddy:r-x",
                    "--",
                    "{}",
                    "+",
                ],
                account.pw_name,
            )
            command(
                [
                    "/usr/bin/setfacl",
                    "-P",
                    "-R",
                    "-n",
                    "-m",
                    "u:caddy:r-X",
                    "--",
                    str(served),
                ],
                account.pw_name,
            )
        for served in [root, *([storage] if storage is not None else [])]:
            command(["/usr/bin/test", "-r", str(served)], "caddy")
            command(["/usr/bin/test", "-x", str(served)], "caddy")
        git = Path(site["checkout"]) / ".git"
        if git.exists():
            command(
                [
                    "/usr/bin/setfacl",
                    "-P",
                    "-R",
                    "-n",
                    "-m",
                    "u:caddy:---",
                    "--",
                    str(git),
                ],
                account.pw_name,
            )
    return {"ready": True}


if __name__ == "__main__":
    print(json.dumps(grant(WORKLOAD_INPUT)))
