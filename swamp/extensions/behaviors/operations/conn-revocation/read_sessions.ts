/** Read one upstream T3 auth snapshot. The workflow bounds the process with timeout. */
export function sessionInventory(frame: unknown): unknown[] | undefined {
    if (typeof frame !== "object" || frame === null) {
        throw new Error("Invalid RPC reply");
    }
    const message = frame as Record<string, unknown>;
    if (message._tag === "Ping") return undefined;
    if (message.requestId !== "1") throw new Error("Unexpected RPC request");
    if (message._tag !== "Chunk" || !Array.isArray(message.values)) {
        throw new Error("Auth access subscription failed");
    }
    const snapshot = message.values[0];
    if (
        snapshot?.type !== "snapshot" || snapshot.version !== 1 ||
        !Array.isArray(snapshot.payload?.clientSessions)
    ) {
        throw new Error("Missing initial auth snapshot");
    }
    return snapshot.payload.clientSessions;
}

export function readSessions(
    baseUrl: string,
    token: string,
): Promise<unknown[]> {
    const url = new URL(`${baseUrl.replace(/\/$/, "")}/ws`);
    url.protocol = url.protocol === "https:" ? "wss:" : "ws:";
    url.searchParams.set("orchestrationProtocol", "2");
    const socket = new WebSocket(url, {
        headers: { Authorization: `Bearer ${token}` },
    });
    return new Promise((resolve, reject) => {
        let settled = false;
        const fail = () => {
            if (!settled) {
                settled = true;
                reject(new Error("conn.revocation_failed"));
            }
            socket.close();
        };
        socket.onopen = () =>
            socket.send(JSON.stringify({
                _tag: "Request",
                id: "1",
                tag: "subscribeAuthAccess",
                payload: {},
                headers: [],
            }));
        socket.onerror = fail;
        socket.onclose = () => {
            if (!settled) fail();
        };
        socket.onmessage = (event) => {
            if (settled) return;
            try {
                if (
                    typeof event.data !== "string" ||
                    new TextEncoder().encode(event.data).length > 4194304
                ) {
                    throw new Error("Invalid RPC frame");
                }
                const frame = JSON.parse(event.data);
                const inventory = sessionInventory(frame);
                if (inventory === undefined) {
                    socket.send(JSON.stringify({ _tag: "Pong" }));
                    return;
                }
                settled = true;
                resolve(inventory);
                socket.close();
            } catch {
                fail();
            }
        };
    });
}

if (import.meta.main) {
    try {
        const rows = await readSessions(
            Deno.env.get("ORBIT_CONN_URL")!,
            Deno.env.get("ORBIT_CONN_ADMIN_SESSION")!,
        );
        await Deno.writeTextFile(
            `${Deno.env.get("ORBIT_CONN_DIRECTORY")}/inventory.json`,
            JSON.stringify(rows),
            { mode: 0o600 },
        );
    } catch {
        console.error("conn.revocation_failed");
        Deno.exit(1);
    }
}
