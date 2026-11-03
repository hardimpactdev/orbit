import { z } from "npm:zod@4.4.3";

const Key = z.string().regex(/^[A-Za-z0-9+/]{43}=$/);
const Peer = z.object({
    public_key: Key.nullable(),
    allowed_ips: z.array(z.string()),
    endpoint_port: z.number().optional(),
    keepalive: z.number().optional(),
});
export const Observation = z.object({
    public_key: Key.nullable(), port: z.number().nullable(),
    exact: z.boolean(), peers: z.array(Peer),
});
type LivePeer = { key: string; allowed: string[]; endpoint?: string; keepalive: number };
type Live = { key?: string; port?: number; peers: LivePeer[] };

/** `wg show all` hides private keys, unlike showconf and dump. Ignore counters and handshake ages here. */
export function wireguard(stdout: string): Live | null {
    let selected = false;
    let result: Live | null = null;
    let peer: LivePeer | null = null;
    for (const raw of stdout.split("\n")) {
        const line = raw.trim();
        if (line.startsWith("interface: ")) {
            selected = line === "interface: orbit";
            if (selected) result = { peers: [] };
            peer = null;
        } else if (selected && result) {
            if (line.startsWith("peer: ")) {
                peer = { key: line.slice(6), allowed: [], keepalive: 0 };
                result.peers.push(peer);
            } else if (!peer && line.startsWith("public key: ")) {
                result.key = Key.parse(line.slice(12));
            } else if (!peer && line.startsWith("listening port: ")) {
                result.port = Number(line.slice(16));
            } else if (peer && line.startsWith("allowed ips: ")) {
                peer.allowed = line.slice(13).split(",").map((s) => s.trim());
            } else if (peer && line.startsWith("endpoint: ")) {
                peer.endpoint = line.slice(10);
            } else if (peer && line.startsWith("persistent keepalive: every ")) {
                peer.keepalive = Number(line.slice(28).split(" ")[0]);
            }
        }
    }
    return result;
}
const same = (a: string[], b: string[]) => JSON.stringify([...a].sort()) === JSON.stringify([...b].sort());

/** Roaming hub endpoints are deliberately not pinned; a peer's endpoint, allowed IPs and keepalive are. */
export function matchesWireguard(expected: z.infer<typeof Observation>, stdout: string, addresses: string): boolean {
    const live = wireguard(stdout);
    if (!live) return false;
    if (expected.public_key !== null && live.key !== expected.public_key) {
        return false;
    }
    if (expected.port !== null && live.port !== expected.port) return false;
    if (expected.exact && live.peers.length !== expected.peers.length) return false;
    for (const peer of expected.peers) {
        const observed = live.peers.find((p) => p.key === peer.public_key);
        if (!observed || !same(peer.allowed_ips, observed.allowed)) return false;
        if (peer.keepalive !== undefined && peer.keepalive !== observed.keepalive) return false;
        if (peer.endpoint_port !== undefined) {
            const endpoint = observed.endpoint?.match(/^(\[[^\]]+\]|[^:]+):(\d+)$/);
            if (!endpoint || Number(endpoint[2]) !== peer.endpoint_port) return false;
            const address = endpoint[1].replace(/^\[|\]$/g, "");
            if (!addresses.split(/\s+/).includes(address)) return false;
        }
    }
    return true;
}
