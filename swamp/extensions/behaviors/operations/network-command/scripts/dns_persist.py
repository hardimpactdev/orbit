"""Persist split DNS policy without restarting the running tunnel."""

import re
import subprocess

from common import RUN, WG, main, write


def persist(data):
    if subprocess.run(['/usr/bin/wg', 'show', 'orbit', 'public-key'], input=None, check=True, **RUN).stdout.strip() != data['public_key']:
        raise ValueError('vpn.peer_key_mismatch')
    path = WG / 'orbit.conf'
    lines = path.read_text().splitlines()
    hooks = [i for i, line in enumerate(lines) if re.match('^PostUp\\s*=\\s*resolvectl dns %i ', line)]
    hook = 'PostUp = resolvectl dns %i ' + data['server'] + '; resolvectl domain %i ' + ' '.join('\\~' + domain for domain in data['domains'])
    if len(hooks) > 1 or lines.count('[Interface]') != 1:
        raise ValueError('wireguard.peer_dns_state_unsupported')
    if hooks:
        lines[hooks[0]] = hook
    else:
        lines.insert(lines.index('[Interface]') + 1, hook)
    candidate = WG / 'orbit-dns.conf'
    try:
        write(candidate, '\n'.join(lines) + '\n')
        subprocess.run(['/usr/bin/wg-quick', 'strip', str(candidate)], input=None, check=True, **RUN).stdout.strip()
        write(path, '\n'.join(lines) + '\n')
        write(WG / 'orbit.dns-link', 'orbit\n' + data['server'] + '\n' + '\n'.join(data['domains']) + '\n')
    finally:
        candidate.unlink(missing_ok=True)
    return {'persisted': True}
if __name__ == '__main__':
    main(persist)
