"""Keep the Gateway recipe on the agent version published for this checkout."""

import json
from pathlib import Path
import re
import subprocess
import sys
import tomllib


REPOSITORY = "nckrtl/orbit"
ARCHITECTURES = {"amd64": "x86_64", "arm64": "aarch64"}


def validate_pin(version, pin):
    if pin["version"] != version:
        raise ValueError(
            f"Agent {version} is not pinned in the recipe ({pin['version']}). "
            "Publish the reviewed agent tag, then update both recipe artifacts before merging."
        )
    if pin["source"] != "artifact" or set(pin["artifacts"]) != set(ARCHITECTURES):
        raise ValueError("The agent recipe must pin both published Linux artifacts.")
    for architecture, target in ARCHITECTURES.items():
        name = f"orbit-agent-{version}-linux-{target}"
        expected = f"https://github.com/{REPOSITORY}/releases/download/agent-v{version}/{name}"
        artifact = pin["artifacts"][architecture]
        if artifact["url"] != expected or not re.fullmatch(r"[a-f0-9]{64}", artifact["sha256"]):
            raise ValueError(f"Invalid agent release URL or SHA-256 for {architecture}.")


def validate_release(version, pin, release, sums):
    tag = f"agent-v{version}"
    if release["tag_name"] != tag or release["draft"] or release["prerelease"]:
        raise ValueError(f"{tag} must be a published stable release.")
    names = {f"orbit-agent-{version}-linux-{target}" for target in ARCHITECTURES.values()}
    if {asset["name"] for asset in release["assets"]} != names | {"SHA256SUMS"}:
        raise ValueError(f"{tag} must publish both Linux binaries and SHA256SUMS.")
    checksums = {}
    for line in sums.splitlines():
        match = re.fullmatch(r"([a-f0-9]{64})  (\S+)", line)
        if not match or match[2] in checksums:
            raise ValueError("Invalid or duplicate SHA256SUMS entry.")
        checksums[match[2]] = match[1]
    if set(checksums) != names:
        raise ValueError("SHA256SUMS must cover both Linux binaries exactly once.")
    for architecture, target in ARCHITECTURES.items():
        name = f"orbit-agent-{version}-linux-{target}"
        if pin["artifacts"][architecture]["sha256"] != checksums[name]:
            raise ValueError(f"Recipe SHA-256 for {architecture} differs from {tag}/SHA256SUMS.")


def main():
    root = Path(__file__).resolve().parents[1]
    version = tomllib.loads((root / "apps/agent/Cargo.toml").read_text())["package"]["version"]
    pin = json.loads((root / "apps/gateway/resources/machine-software/recipe.json").read_text())["tools"]["orbit-agent"]
    validate_pin(version, pin)
    tag = f"agent-v{version}"
    release = json.loads(subprocess.check_output(
        ["gh", "api", f"repos/{REPOSITORY}/releases/tags/{tag}"], text=True, timeout=60
    ))
    sums = subprocess.check_output(
        ["gh", "release", "download", tag, "--repo", REPOSITORY, "--pattern", "SHA256SUMS", "--output", "-"],
        text=True, timeout=60,
    )
    validate_release(version, pin, release, sums)
    print(f"Agent {version}: published tag, both recipe URLs, and SHA-256 pins agree.")


if __name__ == "__main__":
    try:
        main()
    except (ValueError, KeyError, OSError, subprocess.SubprocessError) as error:
        sys.exit(f"Agent release check failed: {error}")
