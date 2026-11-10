import { z } from "npm:zod@4.4.3";

const Id = z.string().regex(/^[a-zA-Z0-9_-]{1,1024}$/);
export const Intent = z.object({
    url: z.string().url(),
    labels: z.array(z.string().max(1024)).max(4096),
    links: z.array(Id).max(4096),
}).strict();
const Observed = z.array(z.object({
    sessionId: Id,
    current: z.boolean().optional(),
    client: z.object({ label: z.string().optional() }).nullable().optional(),
})).max(4096).refine((rows) =>
    new Set(rows.map((row) => row.sessionId)).size === rows.length
);
const Candidate = z.object({
    index: z.number().int().min(0),
    body: z.string(),
});
const Selection = z.object({
    sessions: z.array(Candidate),
    links: z.array(Candidate),
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
) {
    const completed: number[] = [];
    let valid = true;
    let revoked = 0;
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
        } catch {
            valid = false;
        }
    }
    return { valid, stdout: JSON.stringify({ valid, completed, revoked }) };
}
export const behavior = {
    type: "@nckrtl/conn-revocation-plan",
    version: "2026.11.10.1",
    resources: {
        private: {
            schema: z.object({ directory: Directory, reader: z.string() }),
            lifetime: "1d", garbageCollection: 10,
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
            description: "Create private replies and locate the shipped upstream T3 reader",
            arguments: z.object({}),
            execute: async (_args: Record<string, never>, ctx: Context & { extensionFile(path: string): string }) => {
                const reader = ctx.extensionFile("read_sessions.ts");
                const directory = await Deno.makeTempDir({ prefix: "orbit-conn-revoke.", dir: "/tmp" });
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
                    const ids = args.intent.labels.length
                        ? selectSessions(
                            await read(args.directory, "inventory.json"),
                            args.intent.labels,
                        )
                        : [];
                    const value = {
                        sessions: ids.map((sessionId, index) => ({
                            index,
                            body: JSON.stringify({ sessionId }),
                        })),
                        links: args.intent.links.map((id, index) => ({
                            index,
                            body: JSON.stringify({ id }),
                        })),
                    };
                    await ctx.writeResource("selection", "selection", value);
                    return value;
                } catch {
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
            }),
            execute: async (
                args: { directory: string; family: string; statuses: string[] },
                ctx: Context,
            ) => {
                const value = await readReplies(
                    args.directory,
                    args.family,
                    args.statuses,
                );
                await ctx.writeResource("replies", "replies", value);
                return value;
            },
        },
    },
};
