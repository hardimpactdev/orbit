import { z } from "npm:zod@4.4.3";
const Id = z.string().regex(/^[a-zA-Z0-9_-]{1,1024}$/);
const Time = z.string().datetime({ offset: true });
const Intent = z.object({
    url: z.string().url(),
    environment: z.number().int().positive(),
    pairing_id: z.number().int().positive(),
    label: z.string().min(1).max(1024),
    request_body: z.string().min(1),
}).strict();
const Secret = z.string().min(1).max(16384);
const Status = z.string().regex(/^[0-9]{3}$/);
const Directory = z.string().regex(/^\/tmp\/orbit-conn-session\.[A-Za-z0-9]+$/);
// T3's AuthPairingCredentialResult; T3 echoes the label Orbit always sends.
const LinkReply = z.object({
    id: Id,
    expiresAt: Time,
    credential: Secret,
    label: z.string().min(1),
});
// T3's AuthAccessTokenResult from `POST /oauth/token`. Without a DPoP proof T3 issues a plain bearer token.
const TokenReply = z.object({
    access_token: Secret,
    token_type: z.literal("Bearer"),
    expires_in: z.number().int().positive(),
    scope: z.string().min(1),
});
const Link = z.object({
    id: Id,
    expiresAt: Time,
    credential: Secret.meta({ sensitive: true, vaultName: "orbit-control-inputs" }),
    credential_key: z.string(),
}).strict();
const Held = z.object({
    access_token: Secret.meta({ sensitive: true, vaultName: "orbit-control-inputs" }),
}).strict();
const Terminal = z.object({ stdout: z.string() });
const LinkArgs = z.object({
    intent: Intent,
    directory: Directory,
    http_status: Status,
    env: z.object({ ORBIT_CONN_ADMIN_SESSION: Secret }),
});
const HoldArgs = z.object({
    intent: Intent,
    directory: Directory,
    http_status: Status,
    link: z.object({ id: Id, expiresAt: Time }),
    env: z.object({ ORBIT_CONN_ADMIN_SESSION: Secret, ORBIT_CONN_LINK: Secret }),
});
type Context = {
    modelId: string;
    writeResource(spec: string, name: string, data: unknown): Promise<unknown>;
};
async function reply(directory: string, file: string): Promise<unknown> {
    const path = `${directory}/${file}`;
    if ((await Deno.stat(path)).size > 4194304) throw new Error();
    return JSON.parse(await Deno.readTextFile(path));
}
const leaks = (values: string[], secrets: string[]) =>
    values.some((value) => secrets.some((secret) => value.includes(secret)));

/** Reads the minted link and stores it as a native sensitive field; the redeem step reads it from the vault. */
export async function link(args: z.infer<typeof LinkArgs>, ctx: Context) {
    const modelId = z.string().uuid().parse(ctx.modelId);
    // T3 answered, so each refusal below is known and final for this pairing.
    const status = Number(args.http_status.trim());
    if (!(status >= 200 && status < 300)) {
        throw new Error(status === 401 ? "conn.session_rejected" : "conn.request_refused");
    }
    let parsed: z.infer<typeof LinkReply>;
    try {
        parsed = LinkReply.parse(await reply(args.directory, "link.json"));
    } catch {
        throw new Error("conn.response_invalid");
    }
    if (
        parsed.label !== args.intent.label ||
        leaks([parsed.id, parsed.expiresAt], [parsed.credential, args.env.ORBIT_CONN_ADMIN_SESSION])
    ) throw new Error("conn.response_invalid");
    const name = `pairing-${args.intent.pairing_id}`;
    const handle = await ctx.writeResource("link", name, {
        id: parsed.id,
        expiresAt: parsed.expiresAt,
        credential: parsed.credential,
        credential_key: `nckrtl-conn-session-${modelId}-link-link-${name}-credential`,
    });
    return { dataHandles: [handle] };
}

/** Reads the redeemed session, stores it as a native sensitive field and reports only public metadata. */
export async function hold(args: z.infer<typeof HoldArgs>, ctx: Context) {
    const modelId = z.string().uuid().parse(ctx.modelId);
    // T3 refused the link on its pairing endpoint: no session exists, and the unused link expires within minutes.
    const status = Number(args.http_status.trim());
    if (!(status >= 200 && status < 300)) throw new Error("conn.redeem_refused");
    let parsed: z.infer<typeof TokenReply>;
    try {
        parsed = TokenReply.parse(await reply(args.directory, "session.json"));
    } catch {
        // T3 answered 2xx, so a session may exist; the Gateway revokes it by its label.
        throw new Error("conn.session_outcome_unknown");
    }
    if (leaks([parsed.scope], [parsed.access_token, args.env.ORBIT_CONN_ADMIN_SESSION, args.env.ORBIT_CONN_LINK])) {
        throw new Error("conn.session_outcome_unknown");
    }
    const name = `pairing-${args.intent.pairing_id}`;
    const handle = await ctx.writeResource("held", name, { access_token: parsed.access_token });
    const result = {
        backend: "held",
        pairing_id: args.intent.pairing_id,
        pairing_link_id: args.link.id,
        link_expires_at: args.link.expiresAt,
        session_expires_at: new Date(Date.now() + parsed.expires_in * 1000).toISOString(),
        session_ref: {
            vault: "orbit-control-inputs",
            key: `nckrtl-conn-session-${modelId}-hold-held-${name}-access_token`,
        },
        link_ref: {
            vault: "orbit-control-inputs",
            key: `nckrtl-conn-session-${modelId}-link-link-${name}-credential`,
        },
    };
    const terminal = await ctx.writeResource("terminal", "terminal", { stdout: JSON.stringify(result) });
    return { dataHandles: [handle, terminal] };
}
const resource = (schema: z.ZodType) => ({ schema, lifetime: "infinite", garbageCollection: 10 });
export const behavior = {
    type: "@nckrtl/conn-session",
    version: "2026.11.14.1",
    resources: { link: resource(Link), held: resource(Held), terminal: resource(Terminal) },
    methods: {
        link: {
            description: "Read one private pairing-link reply and store the link as a native sensitive field",
            arguments: LinkArgs,
            execute: link,
        },
        hold: {
            description: "Read one private redeem reply and store the session as a native sensitive field",
            arguments: HoldArgs,
            execute: hold,
        },
    },
};
