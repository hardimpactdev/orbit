"""Install the admitted Linux bootstrap prerequisites."""

import json
import os
import subprocess
from pathlib import Path

BOOTSTRAP_INPUT = None


def install(data):
    release = {}
    for line in Path('/etc/os-release').read_text().splitlines():
        if '=' in line:
            key, value = line.split('=', 1)
            release[key] = value.strip('"')
    if release.get('ID') != 'ubuntu' or release.get('VERSION_CODENAME') not in data['releases']:
        raise ValueError('node.bootstrap_os_unsupported')
    env = dict(os.environ, LC_ALL='C', DEBIAN_FRONTEND='noninteractive', NEEDRESTART_MODE='l')
    for args in [
        ['/usr/bin/apt-get', '-o', 'APT::Update::Error-Mode=any', 'update'],
        ['/usr/bin/apt-get', '--no-remove', 'install', '--yes', '--no-install-recommends', '--', *data['packages']],
    ]:
        subprocess.run(args, env=env, stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL,
                       stderr=subprocess.DEVNULL, check=True)
    return {'packages_installed': True}


if __name__ == '__main__':
    print(json.dumps(install(BOOTSTRAP_INPUT)))
