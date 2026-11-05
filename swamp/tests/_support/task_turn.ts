export function piFixture(token: string, workspace?: string) {
    const state = {
        calls: [] as [string, Record<string, unknown> | null][],
        disconnect: false,
        redirect: false,
        malformed: "",
        usage: { total: 4 } as Record<string, unknown>,
        role: "implementer",
        agentText: "agent changed",
        omitReceipt: false,
        thread: 14,
    };
    const server = Deno.serve(
        { hostname: "127.0.0.1", port: 0, onListen() {} },
        async (request) => {
            const path = new URL(request.url).pathname;
            if (request.method === "POST") {
                const body = await request.json();
                state.calls.push([path, body]);
                if (workspace && path.endsWith("/messages")) {
                    await Deno.writeTextFile(
                        `${workspace}/file`,
                        state.agentText + "\n",
                    );
                    if (!state.omitReceipt) {
                        await Deno.writeTextFile(
                            `${workspace}/.git/orbit/receipt.json`,
                            JSON.stringify({
                                thread: state.thread,
                                outcome: state.role === "reviewer"
                                    ? "approved"
                                    : "ready_for_review",
                                summary: state.agentText,
                            }),
                        );
                    }
                }
                if (state.redirect) {
                    return new Response(null, {
                        status: 302,
                        headers: { Location: "/credential-destination" },
                    });
                }
                if (
                    request.headers.get("Authorization") !== `Bearer ${token}`
                ) return new Response(null, { status: 401 });
                return Response.json({ accepted: true });
            }
            if (
                request.headers.get("Authorization") !== `Bearer ${token}`
            ) return new Response(null, { status: 401 });
            state.calls.push([path, null]);
            const lines = [
                JSON.stringify({ state: "working", turnId: "older" }),
            ];
            if (state.malformed) lines.push(state.malformed);
            if (!state.disconnect) {
                lines.push(
                    JSON.stringify({
                        state: "done",
                        turnId: "operation-15",
                        usage: state.usage,
                    }),
                );
            }
            return new Response(lines.join("\n") + "\n");
        },
    );
    return {
        state,
        port: server.addr.port,
        url: `http://127.0.0.1:${server.addr.port}`,
        [Symbol.asyncDispose]: () => server.shutdown(),
    };
}
