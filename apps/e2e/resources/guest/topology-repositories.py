#!/usr/bin/python3
"""Serve public proof payloads on the topology Gateway's isolated Incus listener."""
import ipaddress
import json
import os
import socket
import subprocess
import sys
from pathlib import Path

ROOT = Path('/etc/swamp-proof')
UNITS = Path('/etc/systemd/system')
REPOSITORIES = Path('/srv/swamp-proof/repositories')
DNS = Path('/etc/dnsmasq.d/swamp-proof-repositories.conf')
MARKER = '# Orbit disposable proof fixture\n'


def run(argv, *, timeout=60):
    result = subprocess.run(argv, capture_output=True, text=True, timeout=timeout, check=False)
    if result.returncode:
        raise RuntimeError('e2e.fixture_repository_command_failed')
    return result.stdout


def caddyfile(address):
    return MARKER + '''{
    admin off
    auto_https off
}
https://repositories.orbit {
    bind ''' + address + '''
    tls /etc/swamp-proof/repositories.pem /etc/swamp-proof/repositories.key
    @write {
        path */git-receive-pack
    }
    @write_query query service=git-receive-pack
    respond @write 403
    respond @write_query 403
    reverse_proxy unix//run/swamp-proof-git-http/fcgi.sock {
        transport fastcgi {
            env SCRIPT_FILENAME /usr/lib/git-core/git-http-backend
            env GIT_PROJECT_ROOT /srv/swamp-proof/repositories
            env GIT_HTTP_EXPORT_ALL 1
            env GIT_CONFIG_COUNT 1
            env GIT_CONFIG_KEY_0 safe.directory
            env GIT_CONFIG_VALUE_0 /srv/swamp-proof/repositories/*
            env PATH_INFO {http.request.uri.path}
        }
    }
}
'''


def units():
    return {
        'swamp-proof-git-http.service': MARKER + '''[Unit]
Description=Disposable topology read-only Git backend
After=network.target
[Service]
User=www-data
Group=caddy
RuntimeDirectory=swamp-proof-git-http
RuntimeDirectoryMode=0750
UMask=0007
ExecStart=/usr/sbin/fcgiwrap -f -s unix:/run/swamp-proof-git-http/fcgi.sock
NoNewPrivileges=true
ProtectSystem=strict
ProtectHome=true
PrivateTmp=true
ReadOnlyPaths=/srv/swamp-proof/repositories
[Install]
WantedBy=multi-user.target
''',
        'swamp-proof-repositories.service': MARKER + '''[Unit]
Description=Disposable topology repository HTTPS
Requires=swamp-proof-git-http.service
After=network.target swamp-proof-git-http.service
[Service]
User=caddy
Group=caddy
ExecStart=/usr/bin/caddy run --config /etc/swamp-proof/Caddyfile --adapter caddyfile
AmbientCapabilities=CAP_NET_BIND_SERVICE
CapabilityBoundingSet=CAP_NET_BIND_SERVICE
NoNewPrivileges=true
ProtectSystem=strict
ProtectHome=true
PrivateTmp=true
[Install]
WantedBy=multi-user.target
''',
    }


def owned(path, text, mode=0o644):
    if path.is_symlink() or path.exists() and not path.read_text().startswith(MARKER):
        raise RuntimeError('e2e.fixture_repository_foreign_file')
    path.write_text(text)
    path.chmod(mode)


def configure(address):
    parsed = ipaddress.ip_address(address)
    if parsed.version != 4 or not parsed in ipaddress.ip_network('10.232.0.0/16'):
        raise ValueError('e2e.fixture_repository_address_invalid')
    for path in ['/usr/bin/caddy', '/usr/sbin/fcgiwrap', '/usr/lib/git-core/git-http-backend', '/usr/sbin/dnsmasq']:
        if not os.access(path, os.X_OK):
            raise RuntimeError('e2e.fixture_repository_prerequisite_missing:' + path)
    manifest = units()
    for name in manifest:
        path = UNITS / name
        if path.is_symlink() or path.exists() and not path.read_text().startswith(MARKER):
            raise RuntimeError('e2e.fixture_repository_foreign_file')
    # Stop only our own prior listener. Never replace the product Caddy configuration or unit.
    if (UNITS / 'swamp-proof-repositories.service').is_file():
        run(['systemctl', 'stop', 'swamp-proof-repositories.service'])
    with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as probe:
        try:
            probe.bind((address, 443))
        except OSError:
            raise RuntimeError('e2e.fixture_repository_listener_occupied') from None
    ROOT.mkdir(mode=0o750, parents=True, exist_ok=True)
    run(['php', '/usr/local/bin/topology-repository-certificate.php', address])
    run(['chown', 'root:caddy', str(ROOT), str(ROOT / 'repositories.key'), str(ROOT / 'repositories.pem')])
    (ROOT / 'repositories.key').chmod(0o640)
    (ROOT / 'repositories.pem').chmod(0o644)
    REPOSITORIES.mkdir(mode=0o755, parents=True, exist_ok=True)
    owned(ROOT / 'Caddyfile', caddyfile(address))
    for name, text in manifest.items():
        owned(UNITS / name, text)
    DNS.parent.mkdir(parents=True, exist_ok=True)
    owned(DNS, MARKER + 'bind-dynamic\nlisten-address=127.0.0.1,10.44.0.1\nlocal=/orbit/\n'
          + 'address=/gateway.orbit/10.44.0.1\naddress=/repositories.orbit/' + address + '\n')
    run(['/usr/bin/caddy', 'validate', '--config', str(ROOT / 'Caddyfile'), '--adapter', 'caddyfile'])
    run(['/usr/sbin/dnsmasq', '--test'])
    run(['systemctl', 'daemon-reload'])
    run(['systemctl', 'enable', '--now', *manifest])
    run(['systemctl', 'restart', 'dnsmasq'])
    run(['systemctl', 'is-active', '--quiet', *manifest, 'dnsmasq'])
    return {'fixture_repositories': 'separate-caddy', 'site': 'repositories.orbit'}


def main():
    try:
        if os.geteuid() != 0 or len(sys.argv) != 2:
            raise ValueError('e2e.fixture_repository_arguments_invalid')
        print(json.dumps(configure(sys.argv[1])))
        return 0
    except (OSError, ValueError, RuntimeError, subprocess.SubprocessError) as error:
        code = str(error)
        print(code if code.startswith('e2e.fixture_repository_') else 'e2e.fixture_repository_setup_failed', file=sys.stderr)
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
