import {
    assertEquals,
    assertFalse,
    assertRejects,
} from "jsr:@std/assert@1.0.19";
import { issue } from "./mod.ts";
const credential = "private-issued-sentinel";
const admin = "private-admin-sentinel";
async function fixture(run: (directory: string) => Promise<void>) {
    const directory = await Deno.makeTempDir({
        prefix: "orbit-conn-pairing.",
        dir: "/tmp",
    });
    try {
        await run(directory);
    } finally {
        await Deno.remove(directory, { recursive: true });
    }
}
function args(directory: string, status = "200", id = 7) {
    return {
        intent: {
            url: "http://t3.test",
            pairing_id: id,
            label: "phone",
            request_body: '{"label":"phone"}',
        },
        directory,
        http_status: status,
        env: { ORBIT_CONN_ADMIN_SESSION: admin },
    };
}
Deno.test("T3's reply with its echoed label writes a pairing-specific native sensitive resource and only a public locator", () =>
    fixture(async (directory) => {
        // T3's AuthPairingCredentialResult echoes the requested label; a newer T3 may add fields.
        await Deno.writeTextFile(
            `${directory}/reply.json`,
            JSON.stringify({
                id: "link",
                credential,
                label: "phone",
                expiresAt: "2026-11-01T01:00:00.000Z",
                scopes: ["orchestration:read"],
            }),
        );
        const writes: { spec: string; name: string; data: unknown }[] = [];
        const context = {
            modelId: "00000000-0000-4000-8000-000000000001",
            writeResource: async (
                spec: string,
                name: string,
                data: unknown,
            ) => {
                writes.push({ spec, name, data });
                return {};
            },
        };
        await issue(args(directory), context);
        await issue(args(directory, "201", 8), context);
        assertEquals(writes.map((x) => x.name), [
            "pairing-7",
            "terminal",
            "pairing-8",
            "terminal",
        ]);
        assertEquals(writes[0], {
            spec: "issued",
            name: "pairing-7",
            data: {
                id: "link",
                credential,
                expiresAt: "2026-11-01T01:00:00.000Z",
            },
        });
        for (const [index, id] of [[1, 7], [3, 8]]) {
            const terminal = JSON.stringify(writes[index].data);
            assertFalse(terminal.includes(credential));
            assertFalse(terminal.includes(admin));
            assertEquals(
                JSON.parse((writes[index].data as { stdout: string }).stdout),
                {
                    backend: "issued",
                    pairing_id: id,
                    pairing_link_id: "link",
                    expires_at: "2026-11-01T01:00:00.000Z",
                    credential_ref: {
                        vault: "orbit-control-inputs",
                        key: `nckrtl-conn-pairing-00000000-0000-4000-8000-000000000001-issue-issued-pairing-${id}-credential`,
                    },
                },
            );
        }
    }));
const failures: Record<string, string> = {
    "401": "conn.session_rejected",
    "503": "conn.request_refused",
    "malformed": "conn.response_invalid",
    "no-credential": "conn.response_invalid",
    "no-label": "conn.response_invalid",
    "other-label": "conn.response_invalid",
    "echo": "conn.response_invalid",
    "admin-echo": "conn.response_invalid",
};
for (const [mode, code] of Object.entries(failures)) {
    Deno.test(`${mode} keeps diagnostics public and stores no credential`, () =>
        fixture(async (directory) => {
            let writes = 0;
            await Deno.writeTextFile(
                `${directory}/reply.json`,
                mode === "malformed" ? "not-json" : JSON.stringify({
                    id: mode === "echo"
                        ? credential
                        : mode === "admin-echo"
                        ? admin
                        : "link",
                    ...(mode === "no-credential" ? {} : { credential }),
                    ...(mode === "no-label"
                        ? {}
                        : { label: mode === "other-label" ? "laptop" : "phone" }),
                    expiresAt: "2026-11-01T01:00:00Z",
                }),
            );
            const error = await assertRejects(() =>
                issue(
                    args(
                        directory,
                        ["401", "503"].includes(mode) ? mode : "200",
                    ),
                    {
                        modelId: "00000000-0000-4000-8000-000000000001",
                        writeResource: () => {
                            writes++;
                            return Promise.resolve({});
                        },
                    },
                )
            );
            assertEquals((error as Error).message, code);
            assertFalse(String(error).includes(credential));
            assertFalse(String(error).includes(admin));
            assertEquals(writes, 0);
        }));
}
