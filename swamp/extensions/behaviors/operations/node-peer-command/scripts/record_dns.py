import ipaddress

from common import WG, command, finish, publish


def record(data):
    peer = data["peer"]
    marker = WG / "orbit.dns-link"
    if marker.exists():
        fields = marker.read_text().splitlines()
        if fields[0] != "orbit":
            link = data["snapshot"]["recorded_resolver"]["link"]
            for field in ("dns", "domain"):
                command(["/usr/bin/resolvectl", field, link, ""])
            command(["/usr/bin/resolvectl", "default-route", link, "yes"])
            command(["/usr/bin/networkctl", "reconfigure", link])
            uplink = command(["/usr/bin/resolvectl", "dns"]).stdout
            public = []
            for line in uplink.splitlines():
                if line.startswith("Link ") and "(orbit):" in line:
                    continue
                for value in line.split(":", 1)[-1].split():
                    try:
                        address = ipaddress.ip_address(
                            value.split("%", 1)[0].split("#", 1)[0]
                        )
                    except ValueError:
                        continue
                    if (
                        not address.is_loopback
                        and not address.is_unspecified
                        and str(address) not in (peer["dns_server"], fields[1])
                    ):
                        public.append(address)
            if not public:
                raise RuntimeError("wireguard.peer_public_dns_missing")
    publish(
        WG / "orbit.dns-link",
        "orbit\n" + peer["dns_server"] + "\n" + "\n".join(peer["domains"]) + "\n",
    )
    return {"dns_recorded": True}


finish(record)
