import { z } from "npm:zod@4.4.3";

/**
 * The registration step of a container clone (docs/cli/conn/environment-renew.mmd). The `register` phase of
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

function check(session: { status: number; body: string }): z.infer<typeof Authenticated> {
    if (session.status < 200 || session.status >= 300) throw new Error("conn.session_rejected");
    try {
        return Authenticated.parse(JSON.parse(session.body));
    } catch {
        throw new Error("conn.response_invalid");
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

const RenewArgs = z.object({
    instance_id: z.number().int().positive(),
    folder: z.string().regex(/^\/tmp\/orbit-ansible-[A-Za-z0-9-]+$/),
    url: z.string().regex(/^http:\/\/[0-9.]{7,15}:[0-9]{1,5}$/),
    environment_id: z.string().regex(/^[A-Za-z0-9._-]{1,255}$/),
});
const Issued = z.object({ token: z.string().min(1).max(16384) });

/** A renewal never stores a session that this read of the recorded URL did not prove (docs/cli/conn/environment-renew.mmd). */
async function prove(args: z.infer<typeof RenewArgs>, token: string) {
    const read = async (path: string, headers: Record<string, string> = {}) => {
        try {
            const response = await fetch(args.url + path, { headers, signal: AbortSignal.timeout(10_000) });
            return { status: response.status, body: await response.text() };
        } catch {
            throw new Error("conn.server_unreachable");
        }
    };
    const served = await read("/.well-known/t3/environment");
    let environment: z.infer<typeof Environment>;
    try {
        environment = Environment.parse(JSON.parse(served.body));
    } catch {
        throw new Error("conn.response_invalid");
    }
    if (environment.environmentId !== args.environment_id) throw new Error("conn.environment_mismatch");
    const session = check(await read("/api/auth/session", { Authorization: `Bearer ${token}` }));
    if (!GATEWAY_SCOPES.every((scope) => session.scopes.includes(scope))) {
        throw new Error("conn.admin_scope_missing");
    }
    return { environment, session };
}

/**
 * The renewal of a container's Gateway session. The Node step issued it in the container as `agent` and the copy step brought it
 * here; this step removes that copy on every path, proves the session with one authenticated read, and only then stores it.
 * A refusal returns its `conn.*` code as the run's public report and stores nothing.
 */
export async function renew(args: z.infer<typeof RenewArgs>, ctx: Context) {
    const modelId = z.string().uuid().parse(ctx.modelId);
    const input = RenewArgs.parse(args);
    let report: Record<string, unknown>;
    const handles = [];
    try {
        let token: string;
        try {
            token = Issued.parse(JSON.parse(await Deno.readTextFile(`${input.folder}/issued.json`))).token;
        } catch {
            throw new Error("conn.response_invalid");
        } finally {
            await Deno.remove(input.folder, { recursive: true }).catch(() => undefined);
        }
        const { environment, session } = await prove(input, token);
        handles.push(await ctx.writeResource("session", `gateway-${input.instance_id}`, { token }));
        report = {
            outcome: "succeeded",
            environment_id: environment.environmentId,
            server_version: environment.serverVersion,
            expires_at: session.expiresAt,
            gateway_session: {
                vault: "orbit-control-inputs",
                key: `nckrtl-instance-t3-${modelId}-renew-session-gateway-${input.instance_id}-token`,
            },
        };
    } catch (error) {
        const code = error instanceof Error && /^conn\.[a-z_]{1,80}$/.test(error.message) ? error.message : "conn.response_invalid";
        report = { outcome: "failed", error_code: code };
    }
    handles.push(await ctx.writeResource("terminal", "terminal", { stdout: JSON.stringify(report) }));
    return { dataHandles: handles };
}

export const behavior = {
    type: "@nckrtl/instance-t3",
    version: "2026.11.13.1",
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
        renew: {
            description:
                "Prove a container's renewed Gateway session over the recorded URL, then store it as a native sensitive field",
            arguments: RenewArgs,
            execute: renew,
        },
    },
};
