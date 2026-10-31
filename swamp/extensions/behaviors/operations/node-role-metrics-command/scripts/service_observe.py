"""Read the canonical FPM exporter files, binary and actual service state."""

import hashlib
import json
import stat
import subprocess
import sys
from pathlib import Path

METRICS_INPUT = None


def observe(data, run=subprocess.run):
    directory = Path(data["directory"])
    marker = data["marker"]
    if (directory / "transaction.json").exists():
        raise ValueError("metrics.service_recovery_required")
    if directory.exists() and (not directory.is_dir() or stat.S_IMODE(directory.stat().st_mode) & 0o077 or (directory / ".orbit-owner").read_text() != marker):
        raise ValueError("metrics.service_ownership")

    def read(value):
        path = Path(value)
        if path.is_symlink() or (path.exists() and not path.is_file()):
            raise ValueError("metrics.service_ownership")
        try:
            text = path.read_text()
        except FileNotFoundError:
            return None
        if not text.startswith(marker):
            raise ValueError("metrics.service_ownership")
        return text

    binary = Path(data["artifact"]["path"])
    if binary.exists():
        if not binary.is_file() or binary.is_symlink():
            raise ValueError("metrics.service_ownership")
        with binary.open("rb") as stream:
            if hashlib.file_digest(stream, "sha256").hexdigest() != data["artifact"]["sha256"]:
                raise ValueError("metrics.service_ownership")

    def flag(method):
        result = run(["/usr/bin/systemctl", method, data["service"]], capture_output=True, text=True, check=False)
        value = result.stdout.strip()
        if result.returncode == 0 and value == ("active" if method == "is-active" else "enabled"):
            return True
        if result.returncode != 0 and value in (("inactive", "failed", "unknown") if method == "is-active" else ("disabled", "masked", "not-found")):
            return False
        raise ValueError("metrics.service_inspection_failed")

    result = {"config": read(data["config_path"]), "unit": read(data["unit_path"]),
              "active": flag("is-active"), "enabled": flag("is-enabled"), "binary": binary.exists(),
              "directory_present": directory.exists()}
    if result["unit"] is None and (result["active"] or result["enabled"]):
        raise ValueError("metrics.service_ownership")
    return result


if __name__ == "__main__":
    try:
        print(json.dumps(observe(METRICS_INPUT)))
    except (ValueError, OSError):
        print('{"error_code":"metrics.service_inspection_failed"}')
        sys.exit(1)
