"""Prepare captured served-root links in the selected production release."""

import json
import pwd
import subprocess
from pathlib import Path

WORKLOAD_INPUT = None


def prepare(value):
    home = Path(value["home"])
    account = pwd.getpwnam(value["user"])
    current = home / "current"
    if not current.is_symlink():
        raise ValueError("route.web_root_release_missing")
    release = current.resolve(strict=True)
    if not release.is_relative_to(home / "releases"):
        raise ValueError("app-prod.web_root_invalid")
    sites = [
        {
            "checkout": str(release),
            "relative_root": value["default_web_root"],
            "application": str(release / value["default_directory"]),
        }
    ]
    for served in value["served"]:
        root = release / served["web_root"]
        application = release / served["directory"]
        for path in (root, application):
            if (
                not path.is_dir()
                or path.is_symlink()
                or not path.resolve().is_relative_to(release)
            ):
                raise ValueError("app-prod.web_root_invalid")
        target = (
            "../" * (2 + len(Path(served["directory"]).parts))
            + "env/"
            + (served["directory"] + "/" if served["directory"] else "")
            + ".env"
        )
        environment = application / ".env"
        if environment.is_symlink():
            if environment.readlink().as_posix() != target:
                raise ValueError("app-prod.web_root_invalid")
        elif environment.exists():
            raise ValueError("app-prod.web_root_invalid")
        else:
            subprocess.run(
                [
                    "/usr/sbin/runuser",
                    "-u",
                    account.pw_name,
                    "--",
                    "/usr/bin/ln",
                    "-s",
                    "--",
                    target,
                    str(environment),
                ],
                check=True,
            )
        sites.append(
            {
                "checkout": str(release),
                "relative_root": served["web_root"],
                "application": str(application),
            }
        )
    return {"ready": True, "user": account.pw_name, "sites": sites}


if __name__ == "__main__":
    print(json.dumps(prepare(WORKLOAD_INPUT)))
