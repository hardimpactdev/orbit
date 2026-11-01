"""Observe only public resolver policy and the active public WireGuard identity."""

import ipaddress
import re
import subprocess

from common import RUN, WG, main


def inspect(data):
    subprocess.run(['/usr/bin/systemctl', 'is-active', '--quiet', 'wg-quick@orbit'], input=None, check=True, **RUN).stdout.strip()
    marker = WG / 'orbit.dns-link'
    state = marker.read_text().strip() if marker.exists() else ''
    fields = state.splitlines()
    link = fields[0] if fields else 'orbit'
    if not re.fullmatch('[A-Za-z0-9_.:-]+', link):
        raise ValueError('wireguard.peer_dns_state_unsupported')
    config = (WG / 'orbit.conf').read_text()
    hooks = [line for line in config.splitlines() if re.match('^PostUp\\s*=\\s*resolvectl dns %i ', line)]
    uplink = False
    for line in subprocess.run(['/usr/bin/resolvectl', 'dns'], input=None, check=True, **RUN).stdout.strip().splitlines():
        if not re.match('^(Global|Link [0-9]+ \\([^)]+\\)):', line) or re.match('^Link [0-9]+ \\(orbit\\):', line):
            continue
        for raw in line.split(':', 1)[-1].split():
            try:
                value = ipaddress.ip_address(re.split('[%#]', raw)[0])
            except ValueError:
                continue
            if not value.is_loopback and (not value.is_unspecified) and (str(value) not in [data['server'], fields[1] if len(fields) > 1 else '']):
                uplink = True
    return {'state': state, 'hooks': '\n'.join(hooks), 'dns': subprocess.run(['/usr/bin/resolvectl', 'dns', 'orbit'], input=None, check=True, **RUN).stdout.strip(), 'domains': subprocess.run(['/usr/bin/resolvectl', 'domain', 'orbit'], input=None, check=True, **RUN).stdout.strip(), 'default_route': subprocess.run(['/usr/bin/resolvectl', 'default-route', 'orbit'], input=None, check=True, **RUN).stdout.strip(), 'uplink': uplink, 'old_link': link, 'public_key': subprocess.run(['/usr/bin/wg', 'show', 'orbit', 'public-key'], input=None, check=True, **RUN).stdout.strip()}
if __name__ == '__main__':
    main(inspect)
