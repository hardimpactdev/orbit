#!/usr/bin/env python3
"""Validate Actions syntax, types and context availability with verified actionlint."""
import hashlib
import os
from pathlib import Path
import subprocess
import sys
import tarfile
import tempfile
import urllib.request

VERSION = '1.7.12'
ARCHIVE = f'actionlint_{VERSION}_linux_amd64.tar.gz'
URL = f'https://github.com/rhysd/actionlint/releases/download/v{VERSION}/{ARCHIVE}'
SHA256 = '8aca8db96f1b94770f1b0d72b6dddcb1ebb8123cb3712530b08cc387b349a3d8'
ROOT = Path(__file__).resolve().parents[1]


def verified_archive(cache):
    archive = cache / ARCHIVE
    cache.mkdir(parents=True, exist_ok=True)
    if not archive.exists():
        urllib.request.urlretrieve(URL, archive)
    if hashlib.sha256(archive.read_bytes()).hexdigest() != SHA256:
        raise ValueError('actionlint checksum mismatch')
    return archive


def check(files):
    cache = Path(os.environ.get('XDG_CACHE_HOME', str(Path.home() / '.cache'))) / 'orbit-actionlint'
    archive = verified_archive(cache)
    with tempfile.TemporaryDirectory(prefix='orbit-actionlint-') as scratch:
        binary = Path(scratch) / 'actionlint'
        with tarfile.open(archive) as release:
            with release.extractfile('actionlint') as executable:
                binary.write_bytes(executable.read())
        binary.chmod(0o755)
        # Shell and Python lint are separate checks; this checks the full Actions document.
        return subprocess.run([str(binary), '-config-file', str(ROOT / '.github/actionlint.yaml'),
                               '-shellcheck=', '-pyflakes=', *map(str, files)],
                              cwd=ROOT, check=False).returncode


if __name__ == '__main__':
    sys.exit(check(sys.argv[1:] or [ROOT / '.github/workflows/ci.yml']))
