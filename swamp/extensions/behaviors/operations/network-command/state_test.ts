import { assert, assertEquals, assertFalse } from "jsr:@std/assert@1.0.19";
import { matchesWireguard, wireguard } from "./state.ts";
const local = "A".repeat(43) + "=", remote = "B".repeat(43) + "=";
const show = `interface: orbit
  public key: ${local}
  private key: (hidden)
  listening port: 51820

peer: ${remote}
  endpoint: 192.0.2.10:51820
  allowed ips: 10.44.0.0/24
  latest handshake: 10 seconds ago
  transfer: 1 KiB received, 2 KiB sent
  persistent keepalive: every 25 seconds
`;
const expected = {
    public_key: local,
    port: null,
    exact: true,
    peers: [{
        public_key: remote,
        allowed_ips: ["10.44.0.0/24"],
        endpoint_port: 51820,
        keepalive: 25,
    }],
};
Deno.test("public WireGuard comparison ignores counters and handshake ages", () => {
    assert(
        matchesWireguard(expected, show, "192.0.2.10 STREAM vpn.example.com"),
    );
    assert(
        matchesWireguard(
            expected,
            show.replace("10 seconds", "2 minutes").replace("1 KiB", "5 MiB"),
            "192.0.2.10",
        ),
    );
    assertEquals(wireguard(show)?.peers[0].keepalive, 25);
});
Deno.test("a missing interface, removed peer or wrong allowed IPs needs repair", () => {
    assertFalse(matchesWireguard(expected, "", "192.0.2.10"));
    assertFalse(
        matchesWireguard(
            expected,
            show.replace(`peer: ${remote}`, `peer: ${local}`),
            "192.0.2.10",
        ),
    );
    assertFalse(
        matchesWireguard(
            expected,
            show.replace("10.44.0.0/24", "10.44.0.9/32"),
            "192.0.2.10",
        ),
    );
});
Deno.test("live endpoint and keepalive drift needs repair even when the recorded digest matches", () => {
    assertFalse(
        matchesWireguard(
            expected,
            show.replace("192.0.2.10", "192.0.2.20"),
            "192.0.2.10",
        ),
    );
    assertFalse(
        matchesWireguard(
            expected,
            show.replace("51820\n  allowed", "51821\n  allowed"),
            "192.0.2.10",
        ),
    );
    assertFalse(
        matchesWireguard(
            expected,
            show.replace("every 25", "every 15"),
            "192.0.2.10",
        ),
    );
});
Deno.test("a hub compares its full peer list and port, allowing peers to roam", () => {
    const hub = {
        public_key: local,
        port: 51820,
        exact: true,
        peers: [{ public_key: remote, allowed_ips: ["10.44.0.0/24"] }],
    };
    assert(matchesWireguard(hub, show, ""));
    assertFalse(
        matchesWireguard(
            hub,
            show.replace("listening port: 51820", "listening port: 51821"),
            "",
        ),
    );
    assertFalse(
        matchesWireguard(
            hub,
            show + `\npeer: ${local}\n  allowed ips: 10.44.0.1/32\n`,
            "",
        ),
    );
    assert(
        matchesWireguard(
            { ...hub, exact: false },
            show + `\npeer: ${local}\n  allowed ips: 10.44.0.1/32\n`,
            "",
        ),
    );
});
