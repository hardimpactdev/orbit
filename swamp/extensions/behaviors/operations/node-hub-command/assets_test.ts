import { assertEquals, assertThrows } from "jsr:@std/assert@1.0.19";
import { hubPeerAction } from "./assets.ts";
const peer = {
    node_id: 2,
    name: "guest",
    address: "10.44.0.2",
    public_key: "A".repeat(43) + "=",
};
const intent = {
    schema_version: 1,
    hub_id: 1,
    hub_address: "10.44.0.1/24",
    port: 51820,
    context_node_id: 2,
    inventory: [peer],
    before: peer,
    desired: peer,
};
Deno.test("removal/restoration use only captured context and preserve prior absence", () => {
    const forward = hubPeerAction({ intent, mode: "remove" });
    const restore = hubPeerAction({ intent, mode: "restore-remove" });
    assertEquals(forward.before, peer);
    assertEquals(forward.after, null);
    assertEquals(restore.before, null);
    assertEquals(restore.after, peer);
    assertEquals(restore.restoring, true);
    assertEquals(
        hubPeerAction({
            intent: { ...intent, before: null },
            mode: "restore-remove",
        }).after,
        null,
    );
});
Deno.test("dynamic prepared key is restricted to the exact context and reversed on restore", () => {
    const prepared_peer = { node_id: 2, public_key: "B".repeat(43) + "=" };
    assertEquals(
        hubPeerAction({ intent, mode: "publish", prepared_peer }).after
            ?.public_key,
        prepared_peer.public_key,
    );
    assertEquals(
        hubPeerAction({ intent, mode: "restore-publish", prepared_peer }).after,
        peer,
    );
    assertThrows(() =>
        hubPeerAction({
            intent,
            mode: "publish",
            prepared_peer: { ...prepared_peer, node_id: 3 },
        })
    );
});
