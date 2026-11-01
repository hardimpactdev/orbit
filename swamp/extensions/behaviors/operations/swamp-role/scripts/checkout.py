"""Verify the checkout supplied by the image or the canonical release owner."""

from pathlib import Path

from common import plan, ready, source_sha

if __name__ == "__main__":
    data = plan()
    if source_sha(data) != data["intent"]["source_sha"]:
        raise ValueError("swamp.role_source_not_prepared")
    for name in ("install_release_load.py", "release_load.py", "manifest_grants.py"):
        if not (Path(data["checkout"]) / "platform/swamp-load" / name).is_file():
            raise ValueError("swamp.role_release_supplier_missing")
    ready(source_sha=data["intent"]["source_sha"])
