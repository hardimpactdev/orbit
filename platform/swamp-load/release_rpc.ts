// Read-only native metadata on one authenticated loopback connection.
// Credential bytes and active-run inputs never leave this process.
type Request = { type: string; payload: Record<string, unknown> };
const allowed = new Set([
    "workflow.get",
    "model.type.describe",
    "model.type.search",
    "run.history",
]);

async function main(): Promise<void> {
    const [tokenFile] = Deno.args;
    if (Deno.args.length !== 1) throw new Error("invalid_metadata_invocation");
    const raw = await new Response(Deno.stdin.readable).text();
    if (raw.length > 1024 * 1024) throw new Error("metadata_request_too_large");
    const requests: Request[] = JSON.parse(raw);
    if (
        !Array.isArray(requests) || !requests.length || requests.length > 2048
    ) {
        throw new Error("invalid_metadata_requests");
    }
    for (const request of requests) {
        if (!allowed.has(request.type)) {
            throw new Error("metadata_method_refused");
        }
        if (
            request.type === "run.history" &&
            (Object.keys(request.payload).length !== 1 ||
                request.payload.active !== true)
        ) {
            throw new Error("metadata_history_scope_refused");
        }
    }
    const token = (await Deno.readTextFile(tokenFile)).trim();
    if (!token || /[\r\n]/.test(token)) {
        throw new Error("invalid_metadata_credential");
    }
    const socket = new WebSocket("ws://127.0.0.1:9418", {
        headers: { Authorization: `Bearer ${token}` },
    });
    const waiting = new Map<string, {
        resolve: (value: Record<string, unknown>) => void;
        reject: (error: Error) => void;
    }>();
    const total = setTimeout(() => socket.close(), 150_000);
    try {
        await new Promise<void>((resolve, reject) => {
            const timer = setTimeout(
                () => reject(new Error("metadata_connect_timeout")),
                10_000,
            );
            socket.addEventListener("open", () => {
                clearTimeout(timer);
                resolve();
            }, { once: true });
            socket.addEventListener("error", () => {
                clearTimeout(timer);
                reject(new Error("metadata_connect_failed"));
            }, { once: true });
        });
        socket.addEventListener("message", (event) => {
            try {
                const response = JSON.parse(event.data);
                const pending = waiting.get(response.id);
                if (!pending) return;
                waiting.delete(response.id);
                if (response.type === "error" || !response.payload) {
                    pending.reject(new Error("metadata_read_refused"));
                } else {
                    pending.resolve(response);
                }
            } catch {
                socket.close();
            }
        });
        socket.addEventListener("close", () => {
            for (const pending of waiting.values()) {
                pending.reject(new Error("metadata_connection_closed"));
            }
            waiting.clear();
        });
        const results: Record<string, unknown>[] = [];
        for (let offset = 0; offset < requests.length; offset += 4) {
            const batch = await Promise.all(
                requests.slice(offset, offset + 4).map(async (request) => {
                    const id = crypto.randomUUID();
                    const response = await new Promise<Record<string, unknown>>(
                        (resolve, reject) => {
                            const timer = setTimeout(() => {
                                waiting.delete(id);
                                reject(new Error("metadata_read_timeout"));
                            }, 30_000);
                            waiting.set(id, {
                                resolve: (value) => {
                                    clearTimeout(timer);
                                    resolve(value);
                                },
                                reject: (error) => {
                                    clearTimeout(timer);
                                    reject(error);
                                },
                            });
                            socket.send(JSON.stringify({ ...request, id }));
                        },
                    );
                    if (response.type !== request.type) {
                        throw new Error("metadata_reply_type_mismatch");
                    }
                    const payload = response.payload as Record<string, unknown>;
                    if (request.type !== "run.history") return payload;
                    if (!Array.isArray(payload.runs)) {
                        throw new Error("metadata_history_unreadable");
                    }
                    return {
                        runs: payload.runs.map((run) => ({
                            id: run.id,
                            status: run.status,
                        })),
                    };
                }),
            );
            results.push(...batch);
        }
        console.log(JSON.stringify(results));
    } finally {
        clearTimeout(total);
        socket.close();
    }
}

try {
    await main();
} catch {
    // Do not print native payloads, raw exceptions or credential-bearing metadata.
    console.error("native_metadata_unavailable");
    Deno.exit(1);
}
