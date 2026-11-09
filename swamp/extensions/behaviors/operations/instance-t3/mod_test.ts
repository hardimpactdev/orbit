import { assert, assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { register } from "./mod.ts";

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
