import {
    assertEquals,
    assertFalse,
    assertRejects,
} from "jsr:@std/assert@1.0.19";
import { issue } from "./mod.ts";
const args = {
    intent: {
        url: "http://t3.test/base/",
        pairing_id: 7,
        label: "phone via Orbit",
    },
    env: { ORBIT_CONN_ADMIN_SESSION: "private-admin-sentinel" },
};
const credential = "private-issued-sentinel";
Deno.test("one typed request writes a native sensitive resource then exposes only a vault locator", async () => {
    const writes: { spec: string; data: unknown }[] = [];
    const request = ((url: unknown, init: RequestInit) => {
        assertEquals(url, "http://t3.test/base/api/auth/pairing-token");
        assertEquals(init.redirect, "error");
        assertEquals(
            new Headers(init.headers).get("authorization"),
            "Bearer private-admin-sentinel",
        );
        assertEquals(JSON.parse(String(init.body)).label, args.intent.label);
        return Promise.resolve(
            new Response(
                JSON.stringify({
                    id: "link",
                    credential,
                    expiresAt: "2026-11-01T01:00:00Z",
                }),
            ),
        );
    }) as typeof fetch;
    await issue(args, {
        modelId: "00000000-0000-4000-8000-000000000001",
        writeResource: async (spec, _name, data) => {
            writes.push({ spec, data });
            return {};
        },
    }, request);
    assertEquals(writes.map((x) => x.spec), ["issued", "terminal"]);
    const terminal = JSON.stringify(writes[1].data);
    assertFalse(terminal.includes(credential));
    assertFalse(terminal.includes(args.env.ORBIT_CONN_ADMIN_SESSION));
    assertEquals(
        JSON.parse((writes[1].data as { stdout: string }).stdout).credential_ref
            .key,
        "nckrtl-conn-pairing-00000000-0000-4000-8000-000000000001-issue-issued-issued-credential",
    );
});
for (const mode of ["transport", "refusal", "malformed", "echo"]) {
    Deno.test(`uncertain ${mode} response writes no native token and performs no retry`, async () => {
        let calls = 0, writes = 0;
        const request = (() => {
            calls++;
            if (mode === "transport") throw new Error(credential);
            return Promise.resolve(
                new Response(
                    mode === "malformed" ? "not-json" : JSON.stringify({
                        id: mode === "echo" ? credential : "link",
                        credential,
                        expiresAt: "2026-11-01T01:00:00Z",
                    }),
                    { status: mode === "refusal" ? 503 : 200 },
                ),
            );
        }) as typeof fetch;
        const error = await assertRejects(() =>
            issue(args, {
                modelId: "00000000-0000-4000-8000-000000000001",
                writeResource: () => {
                    writes++;
                    return Promise.resolve({});
                },
            }, request)
        );
        assertFalse(String(error).includes(credential));
        assertEquals(calls, 1);
        assertEquals(writes, 0);
    });
}
