import { z } from "npm:zod@4.4.3";
import { CreateArgs, createResult, removeRun, routeDeferred } from "../instance-dev-command/result.ts";

/**
 * The registration step of a container clone (docs/reference/development-containers.md#t3-registration-and-pairing).
 * `clone-t3` wrote the container's reply into the clone's private folder: the served environment, the two sessions it issued as
 * `agent`, and T3's answer to each session at `/api/auth/session`; `clone-session-copy` brought it to `t3_file`. This step makes the
 * checks of `POST /conn/environments`, stores both sessions as native sensitive fields, removes the copy on every path, and returns
 * the create's result with only their vault keys: a ready clone skips `finish` (docs/cli/instance/container/create.mmd).
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
const Terminal = z.object({ stdout: z.string(), ready: z.boolean().optional(), removed: z.boolean().optional() });
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

/** The run folder of a migrate's registration, which holds `t3.json`. */
export const Args = z.object({
    instance_id: z.number().int().positive(),
    folder: z.string().regex(/^\/tmp\/orbit-ansible-[A-Za-z0-9-]+$/),
});

/**
 * Reads and checks one T3 reply and stores both sessions as native sensitive fields; returns the server with only their vault keys.
 * A refused check throws its `conn.*` code before anything is stored.
 */
async function stored(file: string, instanceId: number, ctx: Context, remove: boolean) {
    const modelId = z.string().uuid().parse(ctx.modelId);
    let reply: z.infer<typeof Reply>;
    let environment: z.infer<typeof Environment>;
    try {
        reply = Reply.parse(JSON.parse(await Deno.readTextFile(file)));
        environment = Environment.parse(JSON.parse(reply.environment));
    } catch {
        throw new Error("conn.response_invalid");
    } finally {
        // The copy holds both session tokens: it goes on every path, before any check can refuse.
        if (remove) await Deno.remove(file).catch(() => undefined);
    }
    const gateway = check(reply.gateway);
    check(reply.swamp);
    if (!GATEWAY_SCOPES.every((scope) => gateway.scopes.includes(scope))) {
        throw new Error("conn.admin_scope_missing");
    }
    if (reply.gateway.token === reply.swamp.token) throw new Error("conn.response_invalid");
    // Swamp names a sensitive field's vault key from `<type>/<modelId>/<METHOD>/<spec>/<instance>/<field>` (data_writer.ts, pinned
    // db3f6373), and the Gateway's grant allows only `...-register-session-...`: so every registration runs as method `register`.
    const key = (name: string) =>
        `nckrtl-instance-t3-${modelId}-register-session-${name}-${instanceId}-token`;
    const handles = [];
    for (const name of ["gateway", "swamp"] as const) {
        handles.push(await ctx.writeResource("session", `${name}-${instanceId}`, { token: reply[name].token }));
    }
    const server = {
        environment_id: environment.environmentId,
        server_version: environment.serverVersion,
        expires_at: gateway.expiresAt,
        gateway_session: { vault: "orbit-control-inputs", key: key("gateway") },
        swamp_session: { vault: "orbit-control-inputs", key: key("swamp") },
    };
    return { handles, server };
}

/** A migrate's registration (workflow-operation-instance-migrate.yaml): the server's receipt, from the run folder's `t3.json`. */
export async function migrateRegister(args: z.infer<typeof Args>, ctx: Context) {
    const { handles, server } = await stored(`${args.folder}/t3.json`, args.instance_id, ctx, false);
    handles.push(await ctx.writeResource("terminal", "terminal", { stdout: JSON.stringify(server) }));
    return { dataHandles: handles };
}

/**
 * A clone create's registration, which also builds the create's result from the same inputs as `finish` (one YAML anchor,
 * `create_inputs`), so a ready clone skips `finish` (docs/cli/instance/container/create.mmd). It removes the copied `t3.json` on
 * every path, and a ready result's run folder.
 */
export async function createRegister(raw: z.input<typeof CreateArgs>, ctx: Context) {
    const args = CreateArgs.parse(raw);
    const instanceId = z.number().int().positive().parse(args.intent.instance_id);
    const { handles, server } = await stored(z.string().parse(args.t3_file), instanceId, ctx, true);
    const container = args.container === null ? null : { ...args.container, register: "succeeded", server: JSON.stringify(server) };
    // A merge that a Node refused left its mark in the run folder, which goes below on a ready result: read it first.
    const route_deferred = await routeDeferred(args.intent, args.route, args.folder);
    const value = createResult(args.intent, { ...args, container, club_key_file: null, t3_part: null, route_deferred });
    // The copy again: a failed unlink above leaves `removed` false, so the cleanup step runs.
    const removed = value.ready ? await removeRun(args.folder, args.t3_file) : false;
    handles.push(await ctx.writeResource("terminal", "terminal", { stdout: value.stdout, ready: value.ready, removed }));
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

/**
 * The one registration method, `register`: the method name is part of the sessions' vault keys, which the Gateway reads and its grant
 * allows. A migrate passes `{instance_id, folder}`; a clone create passes the create's result inputs (`intent` among them). The
 * arguments stay one object, so Swamp still knows each input's name.
 */
export const RegisterArgs = CreateArgs.partial().extend({
    folder: z.string().regex(/^\/tmp\/orbit-ansible-[A-Za-z0-9-]+$/),
    instance_id: z.number().int().positive().optional(),
}).superRefine((value, ctx) => {
    const shape = value.intent === undefined ? Args : CreateArgs;
    const parsed = shape.safeParse(value);
    if (!parsed.success) for (const issue of parsed.error.issues) ctx.addIssue({ ...issue, code: "custom", message: issue.message });
});

export async function register(raw: z.input<typeof RegisterArgs>, ctx: Context) {
    return raw.intent === undefined ? await migrateRegister(Args.parse(raw), ctx) : await createRegister(raw as z.input<typeof CreateArgs>, ctx);
}

export const behavior = {
    type: "@nckrtl/instance-t3",
    version: "2026.11.15.2",
    resources: {
        session: { schema: Stored, lifetime: "infinite", garbageCollection: 10 },
        terminal: { schema: Terminal, lifetime: "infinite", garbageCollection: 10 },
    },
    methods: {
        register: {
            description:
                "Check a T3 reply and store its Gateway and Swamp sessions as native sensitive fields; for a clone create, also return the create's result",
            arguments: RegisterArgs,
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
