import { assert, assertEquals, assertFalse, assertRejects } from "jsr:@std/assert@1.0.19";
import { hold, link } from "./mod.ts";
const linkSecret = "private-link-sentinel";
const sessionSecret = "private-session-sentinel";
const admin = "private-admin-sentinel";
const modelId = "00000000-0000-4000-8000-000000000001";
const intent = {
    url: "http://10.44.0.7:3773",
    environment: 3,
    pairing_id: 7,
    label: "mini via Orbit k3j9x0qa",
    request_body: '{"label":"mini via Orbit k3j9x0qa"}',
};
async function fixture(run: (directory: string) => Promise<void>) {
    const directory = await Deno.makeTempDir({ prefix: "orbit-conn-session.", dir: "/tmp" });
    try {
        await run(directory);
    } finally {
        await Deno.remove(directory, { recursive: true });
    }
}
function recorder() {
    const writes: { spec: string; name: string; data: unknown }[] = [];
    return {
        writes,
        context: {
            modelId,
            writeResource: async (spec: string, name: string, data: unknown) => {
                writes.push({ spec, name, data });
                return {};
            },
        },
    };
}
const linkReply = { id: "link-1", credential: linkSecret, label: intent.label, expiresAt: "2026-11-14T12:18:38.194Z", scopes: ["access:read"] };
// The reply of T3 0.1.7's `POST /oauth/token`, recorded live without its token.
const tokenReply = {
    access_token: sessionSecret,
    issued_token_type: "urn:ietf:params:oauth:token-type:access_token",
    token_type: "Bearer",
    expires_in: 2591999,
    scope: "orchestration:read access:read access:write",
};
const linkArgs = (directory: string, status = "200") => ({ intent, directory, http_status: status, env: { ORBIT_CONN_ADMIN_SESSION: admin } });
const holdArgs = (directory: string, status = "200") => ({
    intent,
    directory,
    http_status: status,
    link: { id: "link-1", expiresAt: linkReply.expiresAt },
    env: { ORBIT_CONN_ADMIN_SESSION: admin, ORBIT_CONN_LINK: linkSecret },
});

Deno.test("the link is stored as a pairing-specific sensitive field with a public key for the redeem step", () =>
    fixture(async (directory) => {
        await Deno.writeTextFile(`${directory}/link.json`, JSON.stringify(linkReply));
        const { writes, context } = recorder();
        await link(linkArgs(directory), context);
        assertEquals(writes, [{
            spec: "link",
            name: "pairing-7",
            data: {
                id: "link-1",
                expiresAt: linkReply.expiresAt,
                credential: linkSecret,
                credential_key: `nckrtl-conn-session-${modelId}-link-link-pairing-7-credential`,
            },
        }]);
    }));

Deno.test("a refused or invalid mint fails with its final code", () =>
    fixture(async (directory) => {
        const { context } = recorder();
        await assertRejects(() => link(linkArgs(directory, "401"), context), Error, "conn.session_rejected");
        await assertRejects(() => link(linkArgs(directory, "503"), context), Error, "conn.request_refused");
        await Deno.writeTextFile(`${directory}/link.json`, JSON.stringify({ ...linkReply, label: "another" }));
        await assertRejects(() => link(linkArgs(directory), context), Error, "conn.response_invalid");
        await Deno.writeTextFile(`${directory}/link.json`, "{");
        await assertRejects(() => link(linkArgs(directory), context), Error, "conn.response_invalid");
    }));

Deno.test("the session is stored as a sensitive field and the result holds only public metadata", () =>
    fixture(async (directory) => {
        await Deno.writeTextFile(`${directory}/session.json`, JSON.stringify(tokenReply));
        const { writes, context } = recorder();
        const before = Date.now();
        await hold(holdArgs(directory), context);
        assertEquals(writes[0], { spec: "held", name: "pairing-7", data: { access_token: sessionSecret } });
        assertEquals(writes[1].spec, "terminal");
        const stdout = (writes[1].data as { stdout: string }).stdout;
        for (const secret of [sessionSecret, linkSecret, admin]) assertFalse(stdout.includes(secret));
        const result = JSON.parse(stdout);
        assertEquals(result.backend, "held");
        assertEquals(result.pairing_id, 7);
        assertEquals(result.pairing_link_id, "link-1");
        assertEquals(result.session_ref.key, `nckrtl-conn-session-${modelId}-hold-held-pairing-7-access_token`);
        assertEquals(result.link_ref.key, `nckrtl-conn-session-${modelId}-link-link-pairing-7-credential`);
        const ends = Date.parse(result.session_expires_at);
        assert(ends >= before + 2591999000 && ends <= Date.now() + 2591999000);
    }));

Deno.test("a refused redeem is final; a 2xx without T3's shape leaves the outcome unknown", () =>
    fixture(async (directory) => {
        const { writes, context } = recorder();
        await assertRejects(() => hold(holdArgs(directory, "401"), context), Error, "conn.redeem_refused");
        await Deno.writeTextFile(`${directory}/session.json`, JSON.stringify({ ...tokenReply, token_type: "DPoP" }));
        await assertRejects(() => hold(holdArgs(directory), context), Error, "conn.session_outcome_unknown");
        await Deno.writeTextFile(`${directory}/session.json`, JSON.stringify({ ...tokenReply, scope: `x ${admin}` }));
        await assertRejects(() => hold(holdArgs(directory), context), Error, "conn.session_outcome_unknown");
        assertEquals(writes, []);
    }));
