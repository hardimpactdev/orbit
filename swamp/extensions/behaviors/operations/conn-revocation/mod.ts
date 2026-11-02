import { z } from "npm:zod@4.4.3";

const Id = z.string().regex(/^[a-zA-Z0-9_-]{1,1024}$/);
export const Intent = z.object({
    url: z.string().url(),
    labels: z.array(z.string().max(1024)).max(4096),
    links: z.array(Id).max(4096),
}).strict();
// One raw T3 `GET /api/auth/clients` reply, read by curl on 209.
const Observed = z.array(z.object({
    sessionId: Id,
    current: z.boolean().optional(),
    client: z.object({ label: z.string().optional() }).passthrough().nullable().optional(),
}).passthrough()).max(4096).refine((rows) =>
    new Set(rows.map((row) => row.sessionId)).size === rows.length
);
// One raw T3 revoke reply.
const Revoked = z.object({ revoked: z.boolean() }).passthrough();
const Selection = z.object({
    sessions: z.array(Id),
    links: z.array(z.object({ id: Id, body: z.string() })),
});
const Authorization = z.object({ absent: z.boolean(), body: z.string() });
const Terminal = z.object({ stdout: z.string() });
type Context = {
    writeResource(spec: string, name: string, data: unknown): Promise<unknown>;
};

function sessions(stdout: string, labels: string[]) {
    return Observed.parse(JSON.parse(stdout)).map((row) => ({
        session_id: row.sessionId,
        current: row.current ?? false,
        owned: labels.includes(row.client?.label ?? "\u0000"),
    }));
}
export function selectSessions(stdout: string, labels: string[]): string[] {
    return sessions(stdout, labels).filter((row) => row.owned && !row.current)
        .map((row) => row.session_id);
}
export function authorizeSession(stdout: string, id: string, labels: string[]) {
    const current = sessions(stdout, labels).find((row) => row.session_id === id);
    if (current && (!current.owned || current.current)) {
        throw new Error("conn.session_owner_changed");
    }
    return { absent: !current, body: JSON.stringify({ sessionId: id }) };
}
/** Child: raw T3 session revoke replies. Parent: each child's terminal output, and the raw link replies. */
export function terminalResult(
    successful: boolean,
    results: string[],
    child = false,
    links: string[] = [],
) {
    if (!successful) {
        return { stdout: '{"error_code":"conn.revocation_failed"}' };
    }
    for (const reply of links) Revoked.parse(JSON.parse(reply));
    const revoked = results.map((value) =>
        child
            ? Number(Revoked.parse(JSON.parse(value)).revoked)
            : z.object({ revoked_sessions: z.number().int().min(0).max(1) }).strict()
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
    version: "2026.11.02.1",
    resources: {
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
        select: {
            description: "Select only admitted non-current session identities",
            arguments: z.object({ intent: Intent, stdout: z.string() }),
            execute: async (
                args: { intent: z.infer<typeof Intent>; stdout: string },
                ctx: Context,
            ) => {
                const value = {
                    sessions: selectSessions(args.stdout, args.intent.labels),
                    links: [...new Set(args.intent.links)].map((id) => ({
                        id,
                        body: JSON.stringify({ id }),
                    })),
                };
                await ctx.writeResource("selection", "selection", value);
                return value;
            },
        },
        authorize: {
            description: "Recheck one session against the fresh T3 observation",
            arguments: z.object({ intent: Intent, stdout: z.string(), session_id: Id }),
            execute: async (
                args: { intent: z.infer<typeof Intent>; stdout: string; session_id: string },
                ctx: Context,
            ) => {
                const value = authorizeSession(args.stdout, args.session_id, args.intent.labels);
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
                links: z.array(z.string()).default([]),
            }),
            execute: async (
                args: {
                    successful: boolean;
                    results: string[];
                    child: boolean;
                    links: string[];
                },
                ctx: Context,
            ) => {
                const value = terminalResult(
                    args.successful,
                    args.results,
                    args.child,
                    args.links,
                );
                await ctx.writeResource("terminal", "terminal", value);
                return value;
            },
        },
    },
};
