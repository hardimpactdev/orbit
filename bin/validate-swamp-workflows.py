"""Validate every tracked workflow with the checksum-verified repository runtime pin."""
import argparse
import hashlib
import json
import os
import re
import subprocess
import urllib.request
from pathlib import Path


def main():
    parser = argparse.ArgumentParser(__doc__)
    parser.add_argument("--cache", type=Path, required=True)
    parser.add_argument("--report", type=Path, required=True)
    args = parser.parse_args()
    source = Path("extensions/behaviors/node-worker/install.ts").read_text()
    pin = re.search(r'export const Swamp = \{\s*version: "([\w.-]+)",\s*sha256: "([a-f0-9]{64})",\s*bytes: (\d+)', source)
    if not pin:
        raise SystemExit("Missing canonical runtime pin")
    version, digest, length = pin.groups()
    if f"swampVersion: {version}" not in Path(".swamp.yaml").read_text():
        raise SystemExit("Runtime and worker pins differ")
    args.cache.mkdir(parents=True, exist_ok=True)
    binary = args.cache / f"swamp-{version}"

    def valid(path):
        return path.exists() and path.stat().st_size == int(length) and hashlib.file_digest(path.open("rb"), "sha256").hexdigest() == digest

    if not valid(binary):
        candidate = binary.with_suffix(".download")
        url = f"https://github.com/swamp-club/swamp/releases/download/v{version}/swamp-linux-x86_64"
        try:
            with urllib.request.urlopen(url, timeout=120) as response, candidate.open("wb") as output:
                while block := response.read(1024 * 1024):
                    output.write(block)
            if not valid(candidate):
                raise SystemExit("Published runtime checksum mismatch")
            candidate.chmod(0o755)
            candidate.replace(binary)
        finally:
            candidate.unlink(missing_ok=True)
    env = {k: v for k, v in os.environ.items() if not k.startswith(("SWAMP_SERVER", "SWAMP_SERVE", "SWAMP_REPO", "SWAMP_CA", "SWAMP_EXTENSIONS"))}
    env.update(SWAMP_NO_UPDATE_CHECK="1", SWAMP_NO_TELEMETRY="1")
    actual = subprocess.run([str(binary), "--version"], capture_output=True, text=True, env=env, check=True)
    if version not in actual.stdout:
        raise SystemExit("Installed runtime version mismatch")
    result = subprocess.run([str(binary), "workflow", "validate", "--json"], capture_output=True, text=True, env=env)
    # Native auto-resolution may emit JSON events before the final validation object.
    start = result.stdout.find('{\n  "workflows"')
    if start < 0:
        raise SystemExit("Native validation returned no workflow report")
    report = json.loads(result.stdout[start:])
    tracked = subprocess.run(["git", "ls-files", "-z", "workflows"], capture_output=True, text=True, check=True).stdout.split("\0")
    expected = sum(p.endswith((".yaml", ".yml")) for p in tracked)
    rows = report.get("workflows", [])
    passed = sum(row.get("passed") is True for row in rows)
    args.report.write_text(json.dumps({"schema_version": 1, "runtime": version, "workflows_total": expected, "workflows_validated": len(rows), "workflows_passing": passed}, indent=2) + "\n")
    print(f"WORKFLOW-VALIDATE workflows_passing={passed}/{expected} runtime={version}")
    if result.returncode or len(rows) != expected or passed != expected:
        print(result.stdout)
        raise SystemExit("Every workflow must pass native validation")


if __name__ == "__main__":
    main()
