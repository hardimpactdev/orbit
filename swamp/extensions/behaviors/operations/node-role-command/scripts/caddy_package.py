"""Install the admitted Caddy release when the current version is below its floor."""
import hashlib
import json
import os
import subprocess
import tempfile
from pathlib import Path

ROLE_INPUT = None


def run(argv):
    return subprocess.run(argv, check=True, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
                          stderr=subprocess.DEVNULL, text=True)


def version():
    try:
        return run(['/usr/bin/caddy', 'version']).stdout.split()[0].removeprefix('v')
    except FileNotFoundError:
        return None


def sufficient(current, minimum):
    if current is None:
        return False
    result = subprocess.run(['/usr/bin/dpkg', '--compare-versions', current, 'ge', minimum],
                            check=False, stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    if result.returncode not in (0, 1):
        raise ValueError('caddy.package_failed')
    return result.returncode == 0


def install(data):
    if sufficient(version(), data['minimum_version']):
        return {'caddy_installed': True}
    if run(['/usr/bin/dpkg', '--print-architecture']).stdout.strip() != 'amd64':
        raise ValueError('caddy.package_failed')
    filename = 'caddy_' + data['release'] + '_linux_amd64.deb'
    url = 'https://github.com/caddyserver/caddy/releases/download/v' + data['release'] + '/' + filename
    with tempfile.TemporaryDirectory(prefix='orbit-caddy-') as directory:
        package = Path(directory) / filename
        run(['/usr/bin/curl', '--fail', '--silent', '--show-error', '--location', '--output', str(package), url])
        if hashlib.sha512(package.read_bytes()).hexdigest() != data['amd64_sha512']:
            raise ValueError('caddy.package_failed')
        os.chmod(directory, 0o755)
        package.chmod(0o644)
        subprocess.run(['/usr/bin/apt-get', '--no-remove', 'install', '--yes', '--no-install-recommends',
                        '-o', 'Dpkg::Options::=--force-confold', '--', str(package)],
                       env=dict(os.environ, DEBIAN_FRONTEND='noninteractive', NEEDRESTART_MODE='l'),
                       stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, check=True)
    if not sufficient(version(), data['minimum_version']):
        raise ValueError('caddy.package_failed')
    return {'caddy_installed': True}


if __name__ == '__main__':
    print(json.dumps(install(ROLE_INPUT)))
