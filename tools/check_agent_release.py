"""Verify a production agent delivery against its published immutable release."""

import json
from pathlib import Path
import re
import subprocess
import sys
from urllib.parse import urlsplit


PLATFORMS = {"linux-x86_64", "linux-aarch64", "macos-aarch64", "macos-x86_64"}


def validate_manifest(manifest):
    commit = manifest["commit"]
    if not isinstance(commit, str) or not re.fullmatch(r"[a-f0-9]{40}", commit):
        raise ValueError("The production delivery must name its full source commit.")
    if not isinstance(manifest["version"], str) or not manifest["version"]:
        raise ValueError("The delivery must name its Cargo version.")
    if set(manifest["artifacts"]) != PLATFORMS:
        raise ValueError("The production delivery must pin both Linux and both macOS artifacts.")
    repository = None
    for platform, artifact in manifest["artifacts"].items():
        url = urlsplit(artifact["url"])
        match = re.fullmatch(
            rf"/([A-Za-z0-9-]+/[A-Za-z0-9._-]+)/releases/download/agent-v{commit}/orbit-agent-{platform}",
            url.path,
        )
        if (url.scheme != "https" or url.netloc != "github.com" or url.query or url.fragment
                or not match or not re.fullmatch(r"[a-f0-9]{64}", artifact["sha256"])):
            raise ValueError(f"Invalid production agent URL or SHA-256 for {platform}.")
        if repository is not None and repository != match[1]:
            raise ValueError("The delivery artifacts must belong to one repository.")
        repository = match[1]
    return repository


def validate_release(manifest, release, sums):
    validate_manifest(manifest)
    tag = f"agent-v{manifest['commit']}"
    if release["tag_name"] != tag or release["draft"] or release["prerelease"]:
        raise ValueError(f"{tag} must be a published stable release.")
    names = {f"orbit-agent-{platform}" for platform in PLATFORMS}
    if {asset["name"] for asset in release["assets"]} != names | {"SHA256SUMS", "manifest.json"}:
        raise ValueError(f"{tag} must publish all four platform binaries, SHA256SUMS, and manifest.json.")
    checksums = {}
    for line in sums.splitlines():
        match = re.fullmatch(r"([a-f0-9]{64})  (\S+)", line)
        if not match or match[2] in checksums:
            raise ValueError("Invalid or duplicate SHA256SUMS entry.")
        checksums[match[2]] = match[1]
    if set(checksums) != names:
        raise ValueError("SHA256SUMS must cover all four platform binaries exactly once.")
    for platform in PLATFORMS:
        if manifest["artifacts"][platform]["sha256"] != checksums[f"orbit-agent-{platform}"]:
            raise ValueError(f"Delivery SHA-256 for {platform} differs from {tag}/SHA256SUMS.")


def main():
    root = Path(__file__).resolve().parents[1]
    path = Path(sys.argv[1]) if len(sys.argv) == 2 else root / "apps/agent/delivery/manifest.json"
    if len(sys.argv) > 2:
        raise ValueError("Usage: check_agent_release.py [production-manifest.json]")
    manifest = json.loads(path.read_text())
    repository = validate_manifest(manifest)
    tag = f"agent-v{manifest['commit']}"
    release = json.loads(subprocess.check_output(
        ["gh", "api", f"repos/{repository}/releases/tags/{tag}"], text=True, timeout=60
    ))
    sums = subprocess.check_output(
        ["gh", "release", "download", tag, "--repo", repository, "--pattern", "SHA256SUMS", "--output", "-"],
        text=True, timeout=60,
    )
    validate_release(manifest, release, sums)
    published = json.loads(subprocess.check_output(
        ["gh", "release", "download", tag, "--repo", repository, "--pattern", "manifest.json", "--output", "-"],
        text=True, timeout=60,
    ))
    validate_release(published, release, sums)
    if (published["commit"] != manifest["commit"] or published["version"] != manifest["version"]
            or validate_manifest(published) != repository):
        raise ValueError("The published manifest identifies another build.")
    print(f"Agent {tag}: production delivery and published checksums agree.")


if __name__ == "__main__":
    try:
        main()
    except (ValueError, KeyError, TypeError, OSError, subprocess.SubprocessError) as error:
        sys.exit(f"Agent release check failed: {error}")
