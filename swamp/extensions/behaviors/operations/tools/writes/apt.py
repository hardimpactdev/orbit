import os
import subprocess
import tempfile
from pathlib import Path

from support import INPUT

# A busy dpkg lock (unattended-upgrades) waits within the phase budget, then fails.
APT = ["/usr/bin/apt-get", "-o", "DPkg::Lock::Timeout=300"]


def install(package):
    subprocess.run([*APT, "install", "--yes", "--no-install-recommends", "--no-remove", "--", package], check=True,
                   env={**os.environ, "DEBIAN_FRONTEND": "noninteractive"}, capture_output=True, text=True)


REPOSITORY = INPUT.get("repository")
if REPOSITORY:
    version = INPUT["candidate"]["version"]
    if not isinstance(version, str) or not version or any(c.isspace() for c in version):
        raise ValueError("Invalid apt repository candidate")
    source = "/etc/apt/sources.list.d/orbit-" + REPOSITORY["name"] + ".sources"
    with tempfile.TemporaryDirectory(prefix="orbit-apt-package-") as directory:
        subprocess.run(
            ["/usr/bin/apt-get", "-o", "Dir::Etc::sourcelist=" + source, "-o", "Dir::Etc::sourceparts=-",
             "-o", "Dir::State::status=/dev/null", "-o", "Dir::Cache::pkgcache=", "-o", "Dir::Cache::srcpkgcache=",
             "download", "--", INPUT["package"] + "=" + version],
            check=True, cwd=directory, capture_output=True, text=True)
        packages = list(Path(directory).glob("*.deb"))
        if len(packages) != 1:
            raise ValueError("Repository download did not return one package")
        install(str(packages[0]))
else:
    install(INPUT["package"])
print('{"ready":true}')
