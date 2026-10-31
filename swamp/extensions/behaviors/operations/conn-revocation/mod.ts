import { z } from "npm:zod@4.4.3";

const Id = z.string().regex(/^[a-zA-Z0-9_-]{1,1024}$/);
export const Intent = z.object({
    url: z.string().url(),
    labels: z.array(z.string().max(1024)).max(4096),
    links: z.array(Id).max(4096),
}).strict();
const Session = z.object({
    session_id: Id,
    current: z.boolean(),
    owned: z.boolean(),
}).strict();
const Sessions = z.array(Session).max(4096).refine((rows) =>
    new Set(rows.map((row) => row.session_id)).size === rows.length
);
const Revoke = z.object({ type: z.enum(["session", "link"]), id: Id }).strict();
const Assets = z.object({
    list_script: z.string(),
    revoke_script: z.string(),
    intent_json: z.string(),
});
const Selection = z.object({
    sessions: z.array(Id),
    links: z.array(z.object({ id: Id, input_json: z.string() })),
});
const Authorization = z.object({ absent: z.boolean(), input_json: z.string() });
const Terminal = z.object({ stdout: z.string() });
type Context = {
    extensionFile(path: string): string;
    writeResource(spec: string, name: string, data: unknown): Promise<unknown>;
};

export function selectSessions(stdout: string): string[] {
    return Sessions.parse(JSON.parse(stdout)).filter((row) =>
        row.owned && !row.current
    ).map((row) => row.session_id);
}
export function authorizeSession(stdout: string, id: string) {
    const current = Sessions.parse(JSON.parse(stdout)).find((row) =>
        row.session_id === id
    );
    if (current && (!current.owned || current.current)) {
        throw new Error("conn.session_owner_changed");
    }
    return {
        absent: !current,
        input_json: JSON.stringify(Revoke.parse({ type: "session", id })),
    };
}
export function terminalResult(
    successful: boolean,
    results: string[],
    child = false,
) {
    if (!successful) {
        return { stdout: '{"error_code":"conn.revocation_failed"}' };
    }
    const revoked = results.map((value) =>
        z.object({ revoked_sessions: z.number().int().min(0).max(1) }).strict()
            .parse(JSON.parse(value)).revoked_sessions
    ).reduce((sum, value) => sum + value, 0);
    return {
        stdout: JSON.stringify(
            child
                ? { revoked_sessions: revoked }
                : { backend: "revoked", revoked_sessions: revoked },
        ),
    };
}

export const behavior = {
    type: "@nckrtl/conn-revocation-plan",
    version: "2026.10.31.1",
    resources: {
        assets: { schema: Assets, lifetime: "infinite", garbageCollection: 10 },
        selection: {
            schema: Selection,
            lifetime: "infinite",
            garbageCollection: 10,
        },
        authorization: {
            schema: Authorization,
            lifetime: "infinite",
            garbageCollection: 10,
        },
        terminal: {
            schema: Terminal,
            lifetime: "infinite",
            garbageCollection: 10,
        },
    },
    methods: {
        assets: {
            description: "Resolve one-request action scripts and public intent",
            arguments: z.object({ intent: Intent }),
            execute: async (
                args: { intent: z.infer<typeof Intent> },
                ctx: Context,
            ) => {
                const value = {
                    list_script: ctx.extensionFile("scripts/list_sessions.py"),
                    revoke_script: ctx.extensionFile("scripts/revoke.py"),
                    intent_json: JSON.stringify(args.intent),
                };
                await ctx.writeResource("assets", "assets", value);
                return value;
            },
        },
        select: {
            description: "Select only admitted non-current session identities",
            arguments: z.object({ intent: Intent, stdout: z.string() }),
            execute: async (
                args: { intent: z.infer<typeof Intent>; stdout: string },
                ctx: Context,
            ) => {
                const value = {
                    sessions: selectSessions(args.stdout),
                    links: [...new Set(args.intent.links)].map((id) => ({
                        id,
                        input_json: JSON.stringify({ type: "link", id }),
                    })),
                };
                await ctx.writeResource("selection", "selection", value);
                return value;
            },
        },
        authorize: {
            description: "Recheck one session against the fresh T3 observation",
            arguments: z.object({ stdout: z.string(), session_id: Id }),
            execute: async (
                args: { stdout: string; session_id: string },
                ctx: Context,
            ) => {
                const value = authorizeSession(args.stdout, args.session_id);
                await ctx.writeResource(
                    "authorization",
                    "authorization",
                    value,
                );
                return value;
            },
        },
        finish: {
            description:
                "Shape the public terminal result after native steps settle",
            arguments: z.object({
                successful: z.boolean(),
                results: z.array(z.string()),
                child: z.boolean().default(false),
            }),
            execute: async (
                args: {
                    successful: boolean;
                    results: string[];
                    child: boolean;
                },
                ctx: Context,
            ) => {
                const value = terminalResult(
                    args.successful,
                    args.results,
                    args.child,
                );
                await ctx.writeResource("terminal", "terminal", value);
                return value;
            },
        },
    },
};
