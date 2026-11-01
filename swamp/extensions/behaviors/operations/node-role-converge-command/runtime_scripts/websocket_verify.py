"""One actual Reverb listener observation; native resume owns a later attempt."""
import json
import socket
import sys
from pathlib import Path

ROLE_INPUT = None


if __name__ == '__main__':
    try:
        if 'release' in ROLE_INPUT:
            install=Path(ROLE_INPUT['install_path'])
            if not install.is_symlink() or install.resolve()!=Path(ROLE_INPUT['release']):
                raise ValueError('websocket.runtime_release_not_ready')
            if Path('/etc/systemd/system/orbit-websocket.service').read_text()!=ROLE_INPUT['unit']:
                raise ValueError('websocket.runtime_unit_not_ready')
        with socket.create_connection(('127.0.0.1', ROLE_INPUT['port']), timeout=3):
            print(json.dumps({'ready':True,'changed':False}))
    except (OSError,ValueError):
        sys.exit(1)
