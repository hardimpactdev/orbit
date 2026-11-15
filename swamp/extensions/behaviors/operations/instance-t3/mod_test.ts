import { assert, assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { register, renew } from "./mod.ts";

const gatewayToken = "private-gateway-sentinel";
const swampToken = "private-swamp-sentinel";
const environment = JSON.stringify({ environmentId: "env-7", label: "t3", serverVersion: "0.1.7" });
const session = (scopes: string[], status = 200) => ({
    status,
    body: JSON.stringify({ authenticated: true, scopes, expiresAt: "2026-12-10T10:00:00.000Z" }),
});

async function fixture(reply: unknown, run: (directory: string) => Promise<void>) {
    const directory = await Deno.makeTempDir({ prefix: "orbit-ansible-", dir: "/tmp" });
    try {
        await Deno.writeTextFile(`${directory}/t3.json`, JSON.stringify(reply));
        await run(directory);
    } finally {
        await Deno.remove(directory, { recursive: true });
    }
}

function context() {
    const writes: { spec: string; name: string; data: unknown }[] = [];
    return {
        writes,
        modelId: "00000000-0000-4000-8000-000000000001",
        writeResource: (spec: string, name: string, data: unknown) => {
            writes.push({ spec, name, data });
            return Promise.resolve({});
        },
    };
}

Deno.test("a checked reply stores both sessions as sensitive fields and returns only their vault keys", () =>
    fixture({
        environment,
        gateway: { token: gatewayToken, ...session(["access:read", "access:write", "orchestration:read"]) },
        swamp: { token: swampToken, ...session(["orchestration:read"]) },
    }, async (run) => {
        const ctx = context();
        await register({ instance_id: 7, folder: run }, ctx);
        assertEquals(ctx.writes.slice(0, 2), [
            { spec: "session", name: "gateway-7", data: { token: gatewayToken } },
            { spec: "session", name: "swamp-7", data: { token: swampToken } },
        ]);
        const terminal = ctx.writes[2];
        assertEquals(terminal.spec, "terminal");
        const stdout = (terminal.data as { stdout: string }).stdout;
        assert(!stdout.includes(gatewayToken) && !stdout.includes(swampToken));
        const prefix = "nckrtl-instance-t3-00000000-0000-4000-8000-000000000001-register-session-";
        assertEquals(JSON.parse(stdout), {
            environment_id: "env-7",
            server_version: "0.1.7",
            expires_at: "2026-12-10T10:00:00.000Z",
            gateway_session: { vault: "orbit-control-inputs", key: `${prefix}gateway-7-token` },
            swamp_session: { vault: "orbit-control-inputs", key: `${prefix}swamp-7-token` },
        });
    }));

Deno.test("each failed check refuses with the code of POST /conn/environments and stores nothing", async () => {
    const cases: [unknown, string][] = [
        [{ environment, gateway: { token: "g", ...session(["access:read"]) }, swamp: { token: "s", ...session([]) } }, "conn.admin_scope_missing"],
        [{ environment, gateway: { token: "g", status: 401, body: "" }, swamp: { token: "s", ...session([]) } }, "conn.session_rejected"],
        [{ environment, gateway: { token: "g", ...session(["access:read", "access:write"]) }, swamp: { token: "s", status: 403, body: "no" } }, "conn.session_rejected"],
        [{ environment: "<html>", gateway: { token: "g", ...session(["access:read", "access:write"]) }, swamp: { token: "s", ...session([]) } }, "conn.response_invalid"],
        [{ environment, gateway: { token: "g", ...session(["access:read", "access:write"], 403) }, swamp: { token: "s", ...session([]) } }, "conn.session_rejected"],
        [{ environment, gateway: { token: "same", ...session(["access:read", "access:write"]) }, swamp: { token: "same", ...session([]) } }, "conn.response_invalid"],
    ];
    for (const [reply, code] of cases) {
        await fixture(reply, async (run) => {
            const ctx = context();
            await assertRejects(() => register({ instance_id: 7, folder: run }, ctx), Error, code);
            assertEquals(ctx.writes, []);
        });
    }
});

const renewToken = "private-renewed-sentinel";

/** A stand-in for the container's T3 server at its recorded URL. */
async function t3Server(served: string, accepts: (authorization: string | null) => Response, run: (url: string) => Promise<void>) {
    const server = Deno.serve({ hostname: "127.0.0.1", port: 0, onListen: () => {} }, (request) => {
        const path = new URL(request.url).pathname;
        if (path === "/.well-known/t3/environment") return new Response(served);
        if (path === "/api/auth/session") return accepts(request.headers.get("authorization"));
        return new Response("", { status: 404 });
    });
    try {
        await run(`http://127.0.0.1:${server.addr.port}`);
    } finally {
        await server.shutdown();
    }
}

async function renewFolder(issued: unknown | null) {
    const folder = await Deno.makeTempDir({ prefix: "orbit-ansible-", dir: "/tmp" });
    if (issued !== null) await Deno.writeTextFile(`${folder}/issued.json`, JSON.stringify(issued));
    return folder;
}

async function gone(path: string) {
    await assertRejects(() => Deno.stat(path), Deno.errors.NotFound);
}

const authenticated = (authorization: string | null) =>
    authorization === `Bearer ${renewToken}`
        ? Response.json({ authenticated: true, scopes: ["access:read", "access:write"], expiresAt: "2027-01-12T10:00:00.000Z" })
        : new Response("", { status: 401 });

Deno.test("a renewal proves the session over the recorded URL, stores it, reports only its vault key, and removes the copy", () =>
    t3Server(environment, authenticated, async (url) => {
        const folder = await renewFolder({ sessionId: "s-1", token: renewToken });
        const ctx = context();
        await renew({ instance_id: 7, folder, url, environment_id: "env-7" }, ctx);
        assertEquals(ctx.writes[0], { spec: "session", name: "gateway-7", data: { token: renewToken } });
        const stdout = (ctx.writes[1].data as { stdout: string }).stdout;
        assert(!stdout.includes(renewToken));
        assertEquals(JSON.parse(stdout), {
            outcome: "succeeded",
            environment_id: "env-7",
            server_version: "0.1.7",
            expires_at: "2027-01-12T10:00:00.000Z",
            gateway_session: {
                vault: "orbit-control-inputs",
                key: "nckrtl-instance-t3-00000000-0000-4000-8000-000000000001-renew-session-gateway-7-token",
            },
        });
        await gone(folder);
    }));

for (
    const [name, served, accepts, code] of [
        ["another environment", JSON.stringify({ environmentId: "env-default", serverVersion: "0.1.7" }), authenticated, "conn.environment_mismatch"],
        ["a refused session", environment, () => new Response("", { status: 401 }), "conn.session_rejected"],
        ["a session without admin scopes", environment, () => Response.json({ authenticated: true, scopes: ["orchestration:read"], expiresAt: "2027-01-12T10:00:00.000Z" }), "conn.admin_scope_missing"],
    ] as const
) {
    Deno.test(`a renewal that meets ${name} reports ${code}, stores nothing, and removes the copy`, () =>
        t3Server(served, accepts, async (url) => {
            const folder = await renewFolder({ token: renewToken });
            const ctx = context();
            await renew({ instance_id: 7, folder, url, environment_id: "env-7" }, ctx);
            assertEquals(ctx.writes.map((write) => write.spec), ["terminal"]);
            assertEquals(JSON.parse((ctx.writes[0].data as { stdout: string }).stdout), { outcome: "failed", error_code: code });
            await gone(folder);
        }));
}

Deno.test("a renewal whose server does not answer reports conn.server_unreachable and removes the copy", async () => {
    const listener = Deno.listen({ hostname: "127.0.0.1", port: 0 });
    const port = (listener.addr as Deno.NetAddr).port;
    listener.close();
    const folder = await renewFolder({ token: renewToken });
    const ctx = context();
    await renew({ instance_id: 7, folder, url: `http://127.0.0.1:${port}`, environment_id: "env-7" }, ctx);
    assertEquals(JSON.parse((ctx.writes[0].data as { stdout: string }).stdout), { outcome: "failed", error_code: "conn.server_unreachable" });
    await gone(folder);
});

Deno.test("a renewal without a readable copy reports conn.response_invalid and removes the folder", async () => {
    const folder = await renewFolder(null);
    const ctx = context();
    await renew({ instance_id: 7, folder, url: "http://127.0.0.1:9", environment_id: "env-7" }, ctx);
    assertEquals(JSON.parse((ctx.writes[0].data as { stdout: string }).stdout), { outcome: "failed", error_code: "conn.response_invalid" });
    await gone(folder);
});
