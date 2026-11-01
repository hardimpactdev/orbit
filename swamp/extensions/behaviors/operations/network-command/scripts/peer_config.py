"""Publish the peer configuration; the private key never leaves this host."""
from common import WG, check_live_address, key, main, write


def configure(data):
    if key() != data['public_key']:
        raise ValueError('vpn.peer_key_mismatch')
    peer = data['peer']
    check_live_address(peer['address'])
    private = (WG / 'orbit.key').read_text().strip()
    config = '[Interface]\nAddress = ' + peer['address'] + '\nPrivateKey = ' + private + '\n\n[Peer]\nPublicKey = ' + peer['server_public_key'] + '\nEndpoint = ' + peer['endpoint'] + '\nAllowedIPs = ' + peer['subnet'] + '\nPersistentKeepalive = 25\n'
    write(WG / 'orbit.conf', config)
    return {'configured': True}
if __name__ == '__main__':
    main(configure)
