"""Use #2312's exact installer; perform no load, grants or service restart."""

from pathlib import Path

from common import managed, module, plan, ready

if __name__ == "__main__":
    data = plan()
    checkout = Path(data["checkout"])
    supplier = module(checkout / "platform/swamp-load/install_release_load.py")
    receipt = supplier.install(
        data["intent"]["source_sha"],
        checkout,
        Path("/usr/local/libexec"),
        lambda *args: managed(
            ["/usr/bin/git", "-C", str(checkout), *args]
        ).stdout.encode(),
    )
    ready(
        source_sha=receipt["source_sha"],
        loads=receipt["loads"],
        restarts=receipt["restarts"],
    )
