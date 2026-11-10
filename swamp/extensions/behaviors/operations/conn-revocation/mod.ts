import { z } from "npm:zod@4.4.3";

const Id = z.string().regex(/^[a-zA-Z0-9_-]{1,1024}$/);
const Pairing = z.object({
    id: z.number().int().positive(),
    label: z.string(),
    ipAddress: z.string().min(1),
    issuedAfter: z.iso.datetime(),
    expiresAt: z.iso.datetime(),
    delivered: z.boolean(),
}).strict();
export const Intent = z.object({
    url: z.string().url(),
    labels: z.array(z.string().max(1024)).max(4096),
    links: z.array(Id).max(4096),
    pairings: z.array(Pairing).max(4096),
}).strict();
const Observed = z.array(z.object({
    sessionId: Id,
    current: z.boolean().optional(),
    subject: z.string().optional(),
    issuedAt: z.iso.datetime().optional(),
    client: z.object({
        label: z.string().optional(),
        ipAddress: z.string().optional(),
    }).nullable().optional(),
})).max(4096).refine((rows) =>
    new Set(rows.map((row) => row.sessionId)).size === rows.length
);
const Candidate = z.object({
    index: z.number().int().min(0),
    body: z.string(),
});
const PairingSessions = z.object({
    id: z.number().int().positive(),
    delivered: z.boolean(),
    indices: z.array(z.number().int().min(0)),
});
const Selection = z.object({
    sessions: z.array(Candidate),
    links: z.array(Candidate),
    pairings: z.array(PairingSessions),
});
const Replies = z.object({ valid: z.boolean(), stdout: z.string() });
const Revoked = z.object({ revoked: z.boolean() });
const Directory = z.string().regex(/^\/tmp\/orbit-conn-revoke\.[A-Za-z0-9]+$/);
type Context = {
    writeResource(spec: string, name: string, data: unknown): Promise<unknown>;
};

export function selectSessions(stdout: string, labels: string[]): string[] {
    return Observed.parse(JSON.parse(stdout))
        .filter((row) =>
            !row.current && labels.includes(row.client?.label ?? "\u0000")
        )
        .map((row) => row.sessionId);
}

/** Conn preserves the grant label; MCP OAuth discards it and requires bounded attribution. */
export function selectPairings(
    stdout: string,
    pairings: z.infer<typeof Pairing>[],
) {
    const rows = Observed.parse(JSON.parse(stdout));
    const selected = pairings.map((pairing) =>
        rows.filter((row) =>
            !row.current && (
                (row.subject === "one-time-token" &&
                    row.client?.label === pairing.label) ||
                (row.subject === "mcp-client" &&
                    row.client?.ipAddress === pairing.ipAddress &&
                    row.issuedAt !== undefined &&
                    Date.parse(row.issuedAt) >=
                        Date.parse(pairing.issuedAfter) &&
                    Date.parse(row.issuedAt) <= Date.parse(pairing.expiresAt))
            )
        ).map((row) => row.sessionId)
    );
    if (
        pairings.some((pairing, index) =>
            pairing.delivered && selected[index].length === 0
        )
    ) {
        throw new Error("conn.revocation_unmatched");
    }
    const ids = rows.filter((row) =>
        selected.some((matches) => matches.includes(row.sessionId))
    ).map((row) => row.sessionId);
    return {
        ids,
        pairings: pairings.map((pairing, index) => ({
            id: pairing.id,
            delivered: pairing.delivered,
            indices: selected[index].map((id) => ids.indexOf(id)),
        })),
    };
}
async function read(directory: string, name: string): Promise<string> {
    const file = `${Directory.parse(directory)}/${name}`;
    if ((await Deno.stat(file)).size > 4194304) {
        throw new Error("conn.revocation_failed");
    }
    return await Deno.readTextFile(file);
}
/** Parse private HTTP replies into public completion metadata, including partial effects. */
export async function readReplies(
    directory: string,
    family: string,
    statuses: string[],
    pairings: z.infer<typeof PairingSessions>[] = [],
) {
    const completed: number[] = [];
    let valid = true;
    let revoked = 0;
    const revokedIndices: number[] = [];
    for (const [index, status] of statuses.entries()) {
        if (status !== "succeeded") {
            valid = false;
            continue;
        }
        try {
            const reply = Revoked.parse(
                JSON.parse(await read(directory, `${family}-${index}.json`)),
            );
            completed.push(index);
            revoked += Number(reply.revoked);
            if (reply.revoked) revokedIndices.push(index);
        } catch {
            valid = false;
        }
    }
    const unmatched = family === "session" &&
        ((pairings.length > 0 && revoked === 0) ||
            pairings.some((pairing) =>
                pairing.delivered &&
                !pairing.indices.some((index) => revokedIndices.includes(index))
            ));
    if (unmatched) valid = false;
    return {
        valid,
        stdout: JSON.stringify({
            valid,
            completed,
            revoked,
            ...(unmatched ? { error_code: "conn.revocation_unmatched" } : {}),
        }),
    };
}
export const behavior = {
    type: "@nckrtl/conn-revocation-plan",
    version: "2026.11.10.3",
    resources: {
        private: {
            schema: z.object({ directory: Directory, reader: z.string() }),
            lifetime: "1d",
            garbageCollection: 10,
        },
        selection: {
            schema: Selection,
            lifetime: "infinite",
            garbageCollection: 10,
        },
        replies: {
            schema: Replies,
            lifetime: "infinite",
            garbageCollection: 10,
        },
    },
    methods: {
        prepare: {
            description:
                "Create private replies and locate the shipped upstream T3 reader",
            arguments: z.object({}),
            execute: async (
                _args: Record<string, never>,
                ctx: Context & { extensionFile(path: string): string },
            ) => {
                const reader = ctx.extensionFile("read_sessions.ts");
                const directory = await Deno.makeTempDir({
                    prefix: "orbit-conn-revoke.",
                    dir: "/tmp",
                });
                const value = { directory, reader };
                await ctx.writeResource("private", "private", value);
                return value;
            },
        },
        select: {
            description:
                "Select admitted non-current sessions from the private T3 reply",
            arguments: z.object({ intent: Intent, directory: Directory }),
            execute: async (
                args: { intent: z.infer<typeof Intent>; directory: string },
                ctx: Context,
            ) => {
                try {
                    const inventory =
                        args.intent.labels.length || args.intent.pairings.length
                            ? await read(args.directory, "inventory.json")
                            : "[]";
                    const selected = selectPairings(
                        inventory,
                        args.intent.pairings,
                    );
                    const ids = args.intent.labels.length
                        ? selectSessions(
                            inventory,
                            args.intent.labels,
                        )
                        : selected.ids;
                    if (args.intent.labels.length === 0 && ids.length === 0) {
                        throw new Error("conn.revocation_unmatched");
                    }
                    const value = {
                        sessions: ids.map((sessionId, index) => ({
                            index,
                            body: JSON.stringify({ sessionId }),
                        })),
                        links: args.intent.links.map((id, index) => ({
                            index,
                            body: JSON.stringify({ id }),
                        })),
                        pairings: selected.pairings,
                    };
                    await ctx.writeResource("selection", "selection", value);
                    return value;
                } catch (error) {
                    if (
                        error instanceof Error &&
                        error.message === "conn.revocation_unmatched"
                    ) throw error;
                    throw new Error("conn.revocation_failed");
                }
            },
        },
        replies: {
            description:
                "Read private revoke replies and retain only valid public completions",
            arguments: z.object({
                directory: Directory,
                family: z.enum(["session", "link"]),
                statuses: z.array(z.string()).max(4096),
                pairings: z.array(PairingSessions).max(4096),
            }),
            execute: async (
                args: {
                    directory: string;
                    family: string;
                    statuses: string[];
                    pairings: z.infer<typeof PairingSessions>[];
                },
                ctx: Context,
            ) => {
                const value = await readReplies(
                    args.directory,
                    args.family,
                    args.statuses,
                    args.pairings,
                );
                await ctx.writeResource("replies", "replies", value);
                return value;
            },
        },
    },
};
