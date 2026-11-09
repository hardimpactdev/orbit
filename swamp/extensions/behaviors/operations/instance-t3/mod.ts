import { z } from "npm:zod@4.4.3";

/**
 * The registration step of a container clone (docs/cli/instance/container/t3-register.mmd). The `register` phase of
 * instance-container.yml wrote the container's reply into the run folder: the served environment, the two sessions it issued as
 * `agent`, and T3's answer to each session at `/api/auth/session`. This step makes the checks of `POST /conn/environments`,
 * stores both sessions as native sensitive fields, and returns only their vault keys. The Gateway records the server from that.
 */
const Session = z.object({
    token: z.string().min(1).max(16384),
    status: z.number().int(),
    body: z.string().max(65536),
});
const Reply = z.object({
    environment: z.string().max(65536),
    gateway: Session,
    swamp: Session,
});
const Environment = z.object({
    environmentId: z.string().regex(/^[A-Za-z0-9._-]{1,255}$/),
    serverVersion: z.string().min(1).max(255),
});
const Authenticated = z.object({
    authenticated: z.literal(true),
    scopes: z.array(z.string()),
    expiresAt: z.string().datetime({ offset: true }),
});
const Stored = z.object({
    token: z.string().min(1).meta({
        sensitive: true,
        vaultName: "orbit-control-inputs",
    }),
}).strict();
const Terminal = z.object({ stdout: z.string() });
const Args = z.object({
    instance_id: z.number().int().positive(),
    folder: z.string().regex(/^\/tmp\/orbit-ansible-[A-Za-z0-9-]+$/),
});
type Context = {
    modelId: string;
    writeResource(spec: string, name: string, data: unknown): Promise<unknown>;
};

/** The Gateway needs these scopes on its own session to mint and revoke device sessions. */
const GATEWAY_SCOPES = ["access:read", "access:write"];

function check(session: z.infer<typeof Session>): z.infer<typeof Authenticated> {
    if (session.status === 401) throw new Error("conn.session_rejected");
    try {
        return Authenticated.parse(JSON.parse(session.body));
    } catch {
        throw new Error(
            session.status >= 200 && session.status < 300
                ? "conn.response_invalid"
                : "conn.session_rejected",
        );
    }
}

export async function register(args: z.infer<typeof Args>, ctx: Context) {
    const modelId = z.string().uuid().parse(ctx.modelId);
    let reply: z.infer<typeof Reply>;
    let environment: z.infer<typeof Environment>;
    try {
        reply = Reply.parse(JSON.parse(await Deno.readTextFile(`${args.folder}/t3.json`)));
        environment = Environment.parse(JSON.parse(reply.environment));
    } catch {
        throw new Error("conn.response_invalid");
    }
    const gateway = check(reply.gateway);
    check(reply.swamp);
    if (!GATEWAY_SCOPES.every((scope) => gateway.scopes.includes(scope))) {
        throw new Error("conn.admin_scope_missing");
    }
    if (reply.gateway.token === reply.swamp.token) throw new Error("conn.response_invalid");
    const key = (name: string) =>
        `nckrtl-instance-t3-${modelId}-register-session-${name}-${args.instance_id}-token`;
    const handles = [];
    for (const name of ["gateway", "swamp"] as const) {
        handles.push(
            await ctx.writeResource("session", `${name}-${args.instance_id}`, {
                token: reply[name].token,
            }),
        );
    }
    const result = {
        environment_id: environment.environmentId,
        server_version: environment.serverVersion,
        expires_at: gateway.expiresAt,
        gateway_session: { vault: "orbit-control-inputs", key: key("gateway") },
        swamp_session: { vault: "orbit-control-inputs", key: key("swamp") },
    };
    handles.push(
        await ctx.writeResource("terminal", "terminal", { stdout: JSON.stringify(result) }),
    );
    return { dataHandles: handles };
}

export const behavior = {
    type: "@nckrtl/instance-t3",
    version: "2026.11.10.1",
    resources: {
        session: { schema: Stored, lifetime: "infinite", garbageCollection: 10 },
        terminal: { schema: Terminal, lifetime: "infinite", garbageCollection: 10 },
    },
    methods: {
        register: {
            description:
                "Check a clone's T3 reply and store its Gateway and Swamp sessions as native sensitive fields",
            arguments: Args,
            execute: register,
        },
    },
};
