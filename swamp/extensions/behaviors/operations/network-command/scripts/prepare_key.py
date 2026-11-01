"""Prepare one peer private key locally; return only its public key."""

import subprocess

from common import KEY, RUN, WG, key, main, write


def prepare(data):
    path = WG / 'orbit.key'
    if not path.exists():
        if data['existing_key'] is not None:
            raise ValueError('wireguard.peer_key_missing')
        private = subprocess.run(['/usr/bin/wg', 'genkey'], input=None, check=True, **RUN).stdout.strip()
        if not KEY.fullmatch(private):
            raise ValueError('wireguard.peer_key_invalid')
        write(path, private + '\n')
    public = key()
    if data['existing_key'] is not None and public != data['existing_key']:
        raise ValueError('vpn.peer_key_mismatch')
    return {'public_key': public}
if __name__ == '__main__':
    main(prepare)
