"""Install the admitted production browser and its canonical AppArmor userns policy."""

import hashlib
import json
import pathlib
import shutil
import subprocess
import sys
import tempfile
import urllib.request
import zipfile

ROOT = pathlib.Path("/opt/orbit/chrome-headless-shell")
POLICY = pathlib.Path("/etc/apparmor.d/orbit-chrome-headless-shell")
BIN = pathlib.Path("/usr/local/bin/chrome-headless-shell")


def install(value):
    with tempfile.TemporaryDirectory(prefix="orbit-enrollment-browser-") as temporary:
        archive = pathlib.Path(temporary) / "browser.zip"
        with (
            urllib.request.urlopen(value["url"]) as response,
            archive.open("wb") as stream,
        ):
            shutil.copyfileobj(response, stream)
        with archive.open("rb") as stream:
            if (
                hashlib.file_digest(stream, value["algorithm"]).hexdigest()
                != value["digest"]
            ):
                raise ValueError("node.tool_artifact_mismatch")
        target = ROOT / value["version"]
        target.mkdir(parents=True, exist_ok=True)
        with zipfile.ZipFile(archive) as bundle:
            bundle.extractall(target)
        binary = target / "chrome-headless-shell-linux64/chrome-headless-shell"
        binary.chmod(0o755)
        version = subprocess.check_output([str(binary), "--version"], text=True).strip()
        if value["version"] not in version.split():
            raise ValueError("node.tool_version_mismatch")
        POLICY.write_text(
            "# Managed by Orbit machine recipe.\nabi <abi/5.0>,\ninclude <tunables/global>\n"
            + "profile orbit-chrome-headless-shell "
            + str(binary)
            + " flags=(unconfined) {\n  userns,\n  @{exec_path} mr,\n}\n"
        )
        subprocess.run(
            ["apparmor_parser", "-r", str(POLICY)], check=True, stdout=sys.stderr
        )
        BIN.write_text('#!/bin/sh\nexec "' + str(binary) + '" "$@"\n')
        BIN.chmod(0o755)
    return {"browser_ready": True}


if __name__ == "__main__":
    try:
        print(json.dumps(install(json.load(sys.stdin))))
    except (ValueError, OSError, KeyError, subprocess.SubprocessError):
        print(json.dumps({"error_code": "node.tool_browser_failed"}))
        sys.exit(1)
