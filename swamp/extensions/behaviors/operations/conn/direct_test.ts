import { assertEquals, assertFalse } from "jsr:@std/assert@1.0.19";
import { connDirect, executeConn, requestT3, type T3 } from "./mod.ts";
import { GatewayOperation } from "../_lib/direct.ts";
import type { Http } from "../../automatic-deploy/gateway.ts";
const identity = {
    operation_id: 17,
    node_id: 9,
    kind: "conn.gateway_sessions_revoke" as const,
    run_id: "a075eb23-7ac4-4487-80d1-57a038a92676",
};
function fixture() {
    const resources = new Map<string, Record<string, unknown>>();
    const projections: unknown[] = [];
    const calls: string[] = [];
    const input = {
        action: "gateway_sessions_revoke",
        environment: {
            id: 1,
            url: "http://t3.test",
            admin_session: "admin-secret-sentinel",
        },
        labels: ["phone via Orbit test"],
        links: ["link-1"],
    };
    let sessions = [
        {
            sessionId: "self",
            current: true,
            client: { label: input.labels[0] },
        },
        { sessionId: "other", client: { label: "unrelated" } },
        { sessionId: "owned", client: { label: input.labels[0] } },
    ];
    const ctx = {
        globalArgs: { gatewayUrl: "https://gateway.orbit", caFile: "/test/ca" },
        readResource: (key: string) =>
            Promise.resolve(resources.get(key) ?? null),
        writeResource: (
            _spec: string,
            key: string,
            value: Record<string, unknown>,
        ) => {
            resources.set(key, value);
            return Promise.resolve({ name: key });
        },
    };
    const safe = () => {
        const { environment: _environment, ...value } = input;
        return structuredClone(value);
    };
    const http: Http = (method, path, raw) => {
        calls.push(path);
        if (path.includes("/private-material")) {
            return Promise.resolve({
                status: 200,
                body: {
                    data: { environment: structuredClone(input.environment) },
                },
            });
        }
        if (method === "POST") projections.push(raw);
        return Promise.resolve({
            status: 200,
            body: {
                data: {
                    id: 17,
                    node_id: 9,
                    kind: identity.kind,
                    input: safe(),
                    status: "queued",
                    cancel_requested: false,
                    result: null,
                },
            },
        });
    };
    const gateway = new GatewayOperation(http, identity);
    const effects: unknown[] = [];
    const t3: T3 = (_method, path, _input, body) => {
        if (path === "/api/auth/clients") {
            return Promise.resolve(structuredClone(sessions));
        }
        effects.push({ path, body });
        return Promise.resolve({ revoked: true });
    };
    return {
        ctx,
        resources,
        input,
        http,
        gateway,
        safe,
        calls,
        effects,
        t3,
        projections,
    };
}
for (
    const kind of ["conn.gateway_sessions_revoke"] as const
) {
    Deno.test(`${kind} preserves current admin and unrelated sessions`, async () => {
        const f = fixture();
        if (kind === "conn.gateway_sessions_revoke") f.input.links = [];
        assertEquals(
            await executeConn(
                { ...identity, kind },
                f.ctx,
                f.gateway,
                f.t3,
                f.safe(),
            ),
            { backend: "revoked", revoked_sessions: 1 },
        );
        assertEquals(
            f.effects.map((e) => (e as { body: unknown }).body),
            [{ sessionId: "owned" }],
        );
    });
}
Deno.test("Conn malformed inspection fails without a revoke or leaked diagnostic", async () => {
    const f = fixture();
    assertEquals(
        await executeConn(
            { ...identity, kind: "conn.gateway_sessions_revoke" },
            f.ctx,
            f.gateway,
            () => Promise.resolve({ credential: "pairing-secret-sentinel" }),
            f.safe(),
        ),
        { error_code: "conn.response_invalid" },
    );
    assertEquals(f.effects.length, 0);
});
Deno.test("Conn changed session owner refuses before revoke", async () => {
    const f = fixture();
    let reads = 0;
    const t3: T3 = (method, path, input, body) => {
        if (path === "/api/auth/clients" && ++reads > 1) {
            return Promise.resolve([{
                sessionId: "owned",
                client: { label: "other-owner" },
            }]);
        }
        return f.t3(method, path, input, body);
    };
    assertEquals(
        await executeConn(
            { ...identity, kind: "conn.gateway_sessions_revoke" },
            f.ctx,
            f.gateway,
            t3,
            f.safe(),
        ),
        { error_code: "conn.session_owner_changed" },
    );
    assertEquals(f.effects.length, 0);
});

Deno.test("Conn transport preserves a registered URL prefix and refuses redirects", async () => {
    const f = fixture();
    f.input.environment.url = "http://t3.test/server/prefix/";
    const previous = globalThis.fetch;
    const seen: { url: string; redirect: RequestRedirect | undefined }[] = [];
    globalThis.fetch = (url, init) => {
        seen.push({ url: String(url), redirect: init?.redirect });
        return Promise.resolve(Response.json({ revoked: true }));
    };
    try {
        assertEquals(
            await requestT3("POST", "/api/auth/clients/revoke", f.input, {
                sessionId: "owned",
            }),
            { revoked: true },
        );
        assertEquals(seen, [{
            url: "http://t3.test/server/prefix/api/auth/clients/revoke",
            redirect: "error",
        }]);
    } finally {
        globalThis.fetch = previous;
    }
});

Deno.test("Conn admin-session rotation mid-revoke stops before an effect", async () => {
    const f = fixture();
    const t3: T3 = (method, path, input, body) => {
        const result = f.t3(method, path, input, body);
        if (path === "/api/auth/clients") {
            f.input.environment.admin_session = "rotated-private-sentinel";
        }
        return result;
    };
    assertEquals(
        await executeConn(
            { ...identity, kind: "conn.gateway_sessions_revoke" },
            f.ctx,
            f.gateway,
            t3,
            f.safe(),
        ),
        { error_code: "conn.environment_changed" },
    );
    assertEquals(f.effects.length, 0);
});
Deno.test("Conn unadmitted link is refused before link revocation", async () => {
    const f = fixture();
    f.input.labels = [];
    const admitted = f.safe();
    f.input.links = [];
    assertEquals(
        await executeConn(
            { ...identity, kind: "conn.gateway_sessions_revoke" },
            f.ctx,
            f.gateway,
            f.t3,
            admitted,
        ),
        { error_code: "conn.environment_changed" },
    );
    assertEquals(f.effects.length, 0);
});

Deno.test("Conn admin rotation during the final inspection cannot revoke the new admin", async () => {
    const f = fixture();
    f.input.links = [];
    let inspections = 0;
    const t3: T3 = (method, path, input, body) => {
        const result = f.t3(method, path, input, body);
        if (path === "/api/auth/clients" && ++inspections === 2) {
            f.input.environment.admin_session = "new-admin-private-sentinel";
        }
        return result;
    };
    assertEquals(
        await executeConn(
            { ...identity, kind: "conn.gateway_sessions_revoke" },
            f.ctx,
            f.gateway,
            t3,
            f.safe(),
        ),
        { error_code: "conn.environment_changed" },
    );
    assertEquals(f.effects.length, 0);
});
