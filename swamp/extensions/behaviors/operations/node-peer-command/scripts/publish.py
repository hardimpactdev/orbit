import ipaddress
import re

from common import WG, finish, publish


def render(data):
    peer = data["peer"]
    private = (WG / "orbit.key").read_text().strip()
    for key in (private, peer["server_public_key"]):
        if not re.fullmatch(r"[A-Za-z0-9+/]{43}=", key):
            raise ValueError("wireguard.peer_key_invalid")
    ipaddress.IPv4Interface(peer["address"])
    ipaddress.IPv4Network(peer["subnet"])
    ipaddress.IPv4Address(peer["dns_server"])
    if not re.fullmatch(r"[A-Za-z0-9.:[\]-]+", peer["endpoint"]):
        raise ValueError("wireguard.peer_endpoint_invalid")
    if any(not re.fullmatch(r"[A-Za-z0-9.-]+", domain) for domain in peer["domains"]):
        raise ValueError("wireguard.peer_domain_invalid")
    domains = " ".join("'~" + domain + "'" for domain in peer["domains"])
    text = (
        "[Interface]\nAddress = " + peer["address"] + "\nPrivateKey = " + private + "\n"
    )
    # Route-only domains make resolved infer default-route no. Setting it here is
    # also refused: Ubuntu's wg-quick AppArmor profile denies SetLinkDefaultRoute.
    text += (
        "PostUp = resolvectl dns %i "
        + peer["dns_server"]
        + "; resolvectl domain %i "
        + domains
        + "\n\n"
    )
    text += (
        "[Peer]\nPublicKey = "
        + peer["server_public_key"]
        + "\nEndpoint = "
        + peer["endpoint"]
        + "\n"
    )
    text += "AllowedIPs = " + peer["subnet"] + "\nPersistentKeepalive = 25\n"
    publish(WG / "orbit.conf", text)
    return {"published": True}


finish(render)
