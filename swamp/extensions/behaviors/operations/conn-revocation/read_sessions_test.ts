import { assertEquals, assertRejects, assertThrows } from "jsr:@std/assert@1";
import { readSessions, sessionInventory } from "./read_sessions.ts";
import { selectSessions } from "./mod.ts";

const recorded = JSON.parse(
    await Deno.readTextFile(
        new URL("./fixtures/stock-0.1.7-client-sessions.json", import.meta.url),
    ),
);
const snapshot = {
    _tag: "Chunk",
    requestId: "1",
    values: [{
        version: 1,
        revision: 1,
        type: "snapshot",
        payload: { pairingLinks: [], clientSessions: recorded },
    }],
};

Deno.test("stock 0.1.7 sessions select only admitted labels and preserve the current admin", () => {
    assertEquals(
        selectSessions(JSON.stringify(sessionInventory(snapshot)), [
            "phone via Orbit fixture",
            "Orbit Gateway",
        ]),
        ["recorded-session-1", "recorded-session-3"],
    );
});

Deno.test("auth subscription refuses failure, wrong request and a missing snapshot", () => {
    for (
        const frame of [
            null,
            {},
            { _tag: "Exit", requestId: "1" },
            { ...snapshot, requestId: "2" },
            { ...snapshot, values: [] },
            { ...snapshot, values: [{ type: "sessionChanged" }] },
        ]
    ) {
        assertThrows(() => sessionInventory(frame));
    }
    assertEquals(sessionInventory({ _tag: "Ping" }), undefined);
});

Deno.test("reader authenticates the upstream RPC and closes after its first snapshot", async () => {
    let authorization: string | null = null;
    let request: unknown;
    const server = Deno.serve({ port: 0, onListen() {} }, (incoming) => {
        authorization = incoming.headers.get("authorization");
        assertEquals(
            new URL(incoming.url).pathname + new URL(incoming.url).search,
            "/ws?orchestrationProtocol=2",
        );
        const { socket, response } = Deno.upgradeWebSocket(incoming);
        socket.onmessage = (event) => {
            request = JSON.parse(event.data);
            socket.send(JSON.stringify(snapshot));
        };
        return response;
    });
    try {
        assertEquals(
            await readSessions(
                `http://127.0.0.1:${server.addr.port}`,
                "private-test-admin",
            ),
            recorded,
        );
        assertEquals(authorization, "Bearer private-test-admin");
        assertEquals(request, {
            _tag: "Request",
            id: "1",
            tag: "subscribeAuthAccess",
            payload: {},
            headers: [],
        });
    } finally {
        await server.shutdown();
    }
});

Deno.test("a rejected admin session never becomes an empty session inventory", async () => {
    const server = Deno.serve(
        { port: 0, onListen() {} },
        () => new Response("unauthorized", { status: 401 }),
    );
    try {
        await assertRejects(
            () =>
                readSessions(
                    `http://127.0.0.1:${server.addr.port}`,
                    "rejected",
                ),
            Error,
            "conn.revocation_failed",
        );
    } finally {
        await server.shutdown();
    }
});
