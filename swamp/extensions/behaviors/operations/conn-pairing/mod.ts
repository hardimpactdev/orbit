import { z } from "npm:zod@4.4.3";
const Id = z.string().regex(/^[a-zA-Z0-9_-]{1,1024}$/);
const Time = z.string().datetime({ offset: true });
const Intent = z.object({
    url: z.string().url(),
    pairing_id: z.number().int().positive(),
    label: z.string().min(1).max(1024),
}).strict();
const Issued = z.object({
    id: Id,
    expiresAt: Time,
    credential: z.string().min(1).max(16384).meta({
        sensitive: true,
        vaultName: "orbit-control-inputs",
    }),
}).strict();
const Terminal = z.object({ stdout: z.string() });
const Args = z.object({
    intent: Intent,
    env: z.object({ ORBIT_CONN_ADMIN_SESSION: z.string().min(1) }),
});
type Context = {
    signal?: AbortSignal;
    modelId: string;
    writeResource(
        spec: string,
        name: string,
        data: unknown,
    ): Promise<unknown>;
};
const Scopes = [
    "orchestration:read",
    "orchestration:operate",
    "settings:write",
    "providers:manage",
    "environment:maintain",
    "preview:operate",
    "diagnostics:read",
    "terminal:read",
    "terminal:operate",
    "source-control:write",
    "filesystem:read",
    "filesystem:write",
    "relay:read",
    "access:read",
    "access:write",
    "relay:write",
];
export async function issue(
    args: z.infer<typeof Args>,
    ctx: Context,
    request: typeof fetch = fetch,
) {
    // Core sensitive-field keys use model type/id, method, resource, instance,
    // and field. Remote DataHandles omit attributes; do not read the secret back.
    const modelId = z.string().uuid().parse(ctx.modelId);
    const credentialKey =
        `nckrtl-conn-pairing-${modelId}-issue-issued-issued-credential`;
    let response: Response;
    try {
        response = await request(
            args.intent.url.replace(/\/+$/, "") + "/api/auth/pairing-token",
            {
                method: "POST",
                redirect: "error",
                signal: ctx.signal,
                headers: {
                    authorization: "Bearer " +
                        args.env.ORBIT_CONN_ADMIN_SESSION,
                    "content-type": "application/json",
                },
                body: JSON.stringify({
                    label: args.intent.label,
                    scopes: Scopes,
                }),
            },
        );
    } catch {
        throw new Error("conn.pairing_outcome_unknown");
    }
    if (!response.ok) {
        await response.body?.cancel();
        throw new Error(
            response.status === 401
                ? "conn.session_rejected"
                : "conn.pairing_outcome_unknown",
        );
    }
    let issued: z.infer<typeof Issued>;
    try {
        issued = Issued.parse(JSON.parse(await response.text()));
    } catch {
        throw new Error("conn.pairing_outcome_unknown");
    }
    if (
        [issued.id, issued.expiresAt].some((value) =>
            value.includes(issued.credential) ||
            value.includes(args.env.ORBIT_CONN_ADMIN_SESSION)
        )
    ) throw new Error("conn.response_invalid");
    const handle = await ctx.writeResource("issued", "issued", issued);
    const result = {
        backend: "issued",
        pairing_id: args.intent.pairing_id,
        pairing_link_id: issued.id,
        expires_at: issued.expiresAt,
        credential_ref: { vault: "orbit-control-inputs", key: credentialKey },
    };
    const terminal = await ctx.writeResource("terminal", "terminal", {
        stdout: JSON.stringify(result),
    });
    return { dataHandles: [handle, terminal] };
}
export const behavior = {
    type: "@nckrtl/conn-pairing",
    version: "2026.11.01.1",
    resources: {
        issued: { schema: Issued, lifetime: "infinite", garbageCollection: 10 },
        terminal: {
            schema: Terminal,
            lifetime: "infinite",
            garbageCollection: 10,
        },
    },
    methods: {
        issue: {
            description:
                "Create one pairing token; native sensitive output stores its credential",
            arguments: Args,
            execute: issue,
        },
    },
};
