import { assertEquals, assertFalse, assertRejects } from "jsr:@std/assert@1.0.19";
import { issue } from "./mod.ts";
const credential = "private-issued-sentinel";
const admin = "private-admin-sentinel";
async function fixture(run: (directory: string) => Promise<void>) {
    const directory = await Deno.makeTempDir({ prefix: "orbit-conn-pairing.", dir: "/tmp" });
    try { await run(directory); } finally { await Deno.remove(directory, { recursive: true }); }
}
function args(directory: string, status = "200", id = 7) {
    return { intent: { url: "http://t3.test", pairing_id: id, label: "phone", request_body: '{"label":"phone"}' }, directory, http_status: status, env: { ORBIT_CONN_ADMIN_SESSION: admin } };
}
Deno.test("private reply writes a pairing-specific native sensitive resource and only a public locator", () => fixture(async (directory) => {
    await Deno.writeTextFile(`${directory}/reply.json`, JSON.stringify({ id: "link", credential, expiresAt: "2026-11-01T01:00:00Z" }));
    const writes: { spec: string; name: string; data: unknown }[] = [];
    const context = { modelId: "00000000-0000-4000-8000-000000000001", writeResource: async (spec: string, name: string, data: unknown) => { writes.push({ spec, name, data }); return {}; } };
    await issue(args(directory), context);
    await issue(args(directory, "201", 8), context);
    assertEquals(writes.map((x) => x.name), ["pairing-7", "terminal", "pairing-8", "terminal"]);
    for (const [index, id] of [[1, 7], [3, 8]]) {
        const terminal = JSON.stringify(writes[index].data);
        assertFalse(terminal.includes(credential));
        assertFalse(terminal.includes(admin));
        assertEquals(JSON.parse((writes[index].data as { stdout: string }).stdout).credential_ref.key,
            `nckrtl-conn-pairing-00000000-0000-4000-8000-000000000001-issue-issued-pairing-${id}-credential`);
    }
}));
for (const mode of ["401", "503", "malformed", "echo", "admin-echo"]) {
    Deno.test(`${mode} keeps diagnostics public and stores no credential`, () => fixture(async (directory) => {
        let writes = 0;
        await Deno.writeTextFile(`${directory}/reply.json`, mode === "malformed" ? "not-json" : JSON.stringify({ id: mode === "echo" ? credential : mode === "admin-echo" ? admin : "link", credential, expiresAt: "2026-11-01T01:00:00Z" }));
        const error = await assertRejects(() => issue(args(directory, ["401", "503"].includes(mode) ? mode : "200"), {
            modelId: "00000000-0000-4000-8000-000000000001",
            writeResource: () => { writes++; return Promise.resolve({}); },
        }));
        assertFalse(String(error).includes(credential));
        assertFalse(String(error).includes(admin));
        assertEquals(writes, 0);
    }));
}
