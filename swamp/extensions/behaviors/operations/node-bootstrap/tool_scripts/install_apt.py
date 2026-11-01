"""Install the enrollment's exact OS package set from its admitted snapshot/artifacts."""

import hashlib
import http.client
import json
import os
import pathlib
import shutil
import subprocess
import sys
import tempfile
import urllib.request

OS_RELEASE = pathlib.Path("/etc/os-release")


def fetch(artifact, path):
    """Try each admitted URL in order, mirror first. A wrong digest fails closed; it never falls back."""
    urls = artifact["urls"]
    for index, url in enumerate(urls):
        try:
            # Like the Node agent's curl --max-time 600: a stalled or broken download moves on.
            with (
                urllib.request.urlopen(url, timeout=600) as response,
                path.open("wb") as target,
            ):
                shutil.copyfileobj(response, target)
        except (OSError, http.client.HTTPException):
            if index == len(urls) - 1:
                raise
            continue
        with path.open("rb") as stream:
            if (
                hashlib.file_digest(stream, artifact["algorithm"]).hexdigest()
                != artifact["digest"]
            ):
                raise ValueError("node.tool_artifact_mismatch")
        return


def install(value):
    release = dict(
        line.split("=", 1)
        for line in OS_RELEASE.read_text().splitlines()
        if "=" in line
    )
    if (
        release.get("ID", "").strip('"') != "ubuntu"
        or release.get("VERSION_CODENAME", "").strip('"') != "resolute"
    ):
        raise ValueError("node.platform_unsupported")
    packages = value["packages"]
    with tempfile.TemporaryDirectory(prefix="orbit-enrollment-apt-") as temporary:
        work = pathlib.Path(temporary)
        sources = work / "sources.list"
        sources.write_text(
            "".join(
                "deb [check-valid-until=no signed-by=/usr/share/keyrings/ubuntu-archive-keyring.gpg] "
                + "https://snapshot.ubuntu.com/ubuntu/"
                + value["snapshot"]
                + "/ "
                + suite
                + " main restricted universe multiverse\n"
                for suite in ("resolute", "resolute-updates", "resolute-security")
            )
        )
        (work / "lists/partial").mkdir(parents=True)
        options = [
            "-o",
            "Dir::Etc::sourcelist=" + str(sources),
            "-o",
            "Dir::Etc::sourceparts=-",
            "-o",
            "Dir::State::Lists=" + str(work / "lists"),
        ]
        argv = []
        for package in packages:
            artifact = package["artifact"]
            if artifact is None:
                argv.append(package["name"] + "=" + package["version"])
            else:
                path = work / (package["name"] + ".deb")
                fetch(artifact, path)
                found = [
                    subprocess.check_output(
                        ["dpkg-deb", "-f", str(path), field], text=True
                    ).strip()
                    for field in ("Package", "Version")
                ]
                if found != [package["name"], package["version"]]:
                    raise ValueError("node.tool_package_mismatch")
                argv.append(str(path))
        environment = dict(os.environ, DEBIAN_FRONTEND="noninteractive")
        subprocess.run(
            ["apt-get", *options, "update"],
            env=environment,
            check=True,
            stdout=sys.stderr,
        )
        subprocess.run(
            [
                "apt-get",
                *options,
                "install",
                "--yes",
                "--no-install-recommends",
                "--no-remove",
                "--",
                *argv,
            ],
            env=environment,
            check=True,
            stdout=sys.stderr,
        )
        for package in packages:
            actual = subprocess.check_output(
                ["dpkg-query", "-W", "-f=${Version}", package["name"]], text=True
            )
            if actual != package["version"]:
                raise ValueError("node.tool_version_mismatch")
    return {"apt_ready": True}


if __name__ == "__main__":
    try:
        print(json.dumps(install(json.load(sys.stdin))))
    except (ValueError, OSError, KeyError, subprocess.SubprocessError):
        print(json.dumps({"error_code": "node.tool_apt_failed"}))
        sys.exit(1)
