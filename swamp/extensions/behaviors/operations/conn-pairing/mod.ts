import { z } from "npm:zod@4.4.3";
const Id = z.string().regex(/^[a-zA-Z0-9_-]{1,1024}$/);
const Time = z.string().datetime({ offset: true });
const Intent = z.object({
    url: z.string().url(),
    pairing_id: z.number().int().positive(),
    label: z.string().min(1).max(1024),
    request_body: z.string().min(1),
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
    directory: z.string().regex(/^\/tmp\/orbit-conn-pairing\.[A-Za-z0-9]+$/),
    http_status: z.string().regex(/^[0-9]{3}$/),
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
export async function issue(
    args: z.infer<typeof Args>,
    ctx: Context,
) {
    // Core sensitive-field keys use model type/id, method, resource, instance,
    // and field. Remote DataHandles omit attributes; do not read the secret back.
    const modelId = z.string().uuid().parse(ctx.modelId);
    const credentialKey =
        `nckrtl-conn-pairing-${modelId}-issue-issued-pairing-${args.intent.pairing_id}-credential`;
    const status = Number(args.http_status.trim());
    if (!(status >= 200 && status < 300)) {
        throw new Error(
            status === 401
                ? "conn.session_rejected"
                : "conn.pairing_outcome_unknown",
        );
    }
    let issued: z.infer<typeof Issued>;
    try {
        const file = `${args.directory}/reply.json`;
        if ((await Deno.stat(file)).size > 4194304) throw new Error();
        issued = Issued.parse(JSON.parse(await Deno.readTextFile(file)));
    } catch {
        throw new Error("conn.pairing_outcome_unknown");
    }
    if (
        [issued.id, issued.expiresAt].some((value) =>
            value.includes(issued.credential) ||
            value.includes(args.env.ORBIT_CONN_ADMIN_SESSION)
        )
    ) throw new Error("conn.response_invalid");
    const handle = await ctx.writeResource(
        "issued",
        `pairing-${args.intent.pairing_id}`,
        issued,
    );
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
    version: "2026.11.02.2",
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
                "Read one private pairing reply and store its unique native sensitive credential",
            arguments: Args,
            execute: issue,
        },
    },
};
