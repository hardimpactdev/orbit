import re

from common import WG, command, finish, publish


def prepare(data):
    path = WG / "orbit.key"
    if not path.exists():
        if data.get("existing_key"):
            raise ValueError("wireguard.peer_key_missing")
        publish(path, command(["/usr/bin/wg", "genkey"]).stdout.strip() + "\n")
    public = command(["/usr/bin/wg", "pubkey"], path.read_text()).stdout.strip()
    if not re.fullmatch(r"[A-Za-z0-9+/]{43}=", public):
        raise ValueError("wireguard.peer_key_invalid")
    return {"public_key": public}


finish(prepare)
