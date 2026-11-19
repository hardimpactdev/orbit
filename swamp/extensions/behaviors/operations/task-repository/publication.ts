import { z } from "npm:zod@4.4.3";
import {
    baseFacts,
    copyCheck,
    imported,
    PublishInput,
    pullPlan,
    pushed,
    run,
    withWaivers,
} from "../tasks/publish.ts";

type Context = {
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};
const Args = z.record(z.string(), z.unknown());
const Observed = z.object({ input: PublishInput, steps: Args });
const PrivateReply = z.object({
    directory: z.string().startsWith("/"),
    exit_code: z.number().int(),
});
const save = async (ctx: Context, value: unknown) => ({
    dataHandles: [await ctx.writeResource("public", "output", value)],
});
async function read(directory: string, name: string): Promise<string> {
    try {
        const path = `${directory}/${name}`;
        if ((await Deno.stat(path)).size > 4194304) throw new Error();
        return await Deno.readTextFile(path);
    } catch {
        throw new Error("tasks.publish_failed");
    }
}
/** A file of the scratch, or null when the copy held none. Any other error of the read fails the publish. */
async function optional(
    directory: string,
    name: string,
): Promise<string | null> {
    try {
        await Deno.lstat(`${directory}/${name}`);
    } catch (error) {
        if (error instanceof Deno.errors.NotFound) return null;
        throw new Error("tasks.publish_failed");
    }
    return await read(directory, name);
}
const Waivers = z.object({
    input: PublishInput,
    directory: z.string().startsWith("/"),
});
const requireResult = <T extends { error_code: string }>(value: T): T => {
    if (value.error_code) throw new Error(value.error_code);
    return value;
};

function requireSuccess<T extends { error_code: string }>(
    value: T,
    key: keyof T,
    fallback: string,
): T {
    if (value[key] !== true) throw new Error(value.error_code || fallback);
    return value;
}

/** Typed comparisons and private CLI reply shaping only; no execution or HTTP. */
export const behavior = {
    type: "@nckrtl/task-publication-data",
    version: "2026.11.19.1",
    resources: {
        public: {
            description: "Public publication observations",
            schema: z.record(z.string(), z.unknown()),
            lifetime: "infinite",
            garbageCollection: 10,
        },
    },
    methods: {
        imported: {
            description:
                "Compare the imported bundle with the admitted commit and tree",
            arguments: Observed,
            execute: (args: z.infer<typeof Observed>, ctx: Context) =>
                save(
                    ctx,
                    requireSuccess(
                        imported(
                            run(args.steps, "import"),
                            args.input.commit,
                            args.input.tree,
                        ),
                        "imported",
                        "tasks.repository_execution_unknown",
                    ),
                ),
        },
        pushed: {
            description:
                "Read private push replies and keep only the public outcome",
            arguments: PrivateReply,
            execute: async (args: z.infer<typeof PrivateReply>, ctx: Context) =>
                save(
                    ctx,
                    requireSuccess(
                        pushed({
                            exitCode: args.exit_code,
                            stdout: await read(args.directory, "push.stdout"),
                            stderr: await read(args.directory, "push.stderr"),
                        }),
                        "pushed",
                        "tasks.push_failed",
                    ),
                ),
        },
        waivers: {
            description:
                "Write the PR title and body a copy publishes: the body plus the proposed body's waiver lines it lacks",
            arguments: Waivers,
            execute: async (args: z.infer<typeof Waivers>, ctx: Context) => {
                const pull = args.input.pull_request;
                if (pull === null) throw new Error("tasks.publish_failed");
                // The proposed body is data that the export fetched beside the bundle; only its waiver lines are read.
                const body = withWaivers(
                    pull.body,
                    await optional(args.directory, "proposed-body"),
                );
                await Deno.writeTextFile(
                    `${args.directory}/pull-title`,
                    pull.title,
                    { mode: 0o600 },
                );
                await Deno.writeTextFile(`${args.directory}/pull-body`, body, {
                    mode: 0o600,
                });
                return save(ctx, { added: body !== pull.body });
            },
        },
        pull: {
            description:
                "Plan one PR write and materialize its JSON input, with the title and body a copy's check read and its Base section",
            arguments: PrivateReply.extend({
                input: PublishInput,
                base: z.string().optional(),
            }),
            execute: async (
                args: z.infer<typeof PrivateReply> & {
                    input: z.infer<typeof PublishInput>;
                    base?: string;
                },
                ctx: Context,
            ) => {
                // A copy publishes exactly the text its .env content check read; any other publish sends the caller's text.
                const title = await optional(args.directory, "pull-title");
                const body = await optional(args.directory, "pull-body");
                const input =
                    args.input.pull_request !== null && title !== null &&
                        body !== null
                        ? {
                            ...args.input,
                            pull_request: {
                                ...args.input.pull_request,
                                title,
                                body,
                            },
                        }
                        : args.input;
                const plan = requireResult(
                    pullPlan({
                        input,
                        base: args.base,
                        steps: {
                            read_pull: {
                                outputs: {
                                    exitCode: args.exit_code,
                                    stdout: await read(
                                        args.directory,
                                        "pulls.json",
                                    ),
                                },
                            },
                        },
                    }),
                );
                const path = `${args.directory}/pull-body.json`;
                await Deno.writeTextFile(path, plan.body, { mode: 0o600 });
                return save(ctx, {
                    method: plan.method,
                    path: plan.path,
                    number: plan.number,
                    body_file: path,
                });
            },
        },
        scan: {
            description:
                "Read the copy check of a publication: pass with its public facts, or refuse with paths, lines and secret names",
            arguments: z.object({ evidence: z.unknown() }),
            execute: (args: { evidence: unknown }, ctx: Context) =>
                save(ctx, requireSuccess(copyCheck(args.evidence), "checked", "tasks.publish_secret_unchecked")),
        },
        content: {
            description:
                "Read the .env content check: pass, or fail with its refusal line or tasks.publish_secret_unchecked",
            arguments: PrivateReply,
            execute: async (
                args: z.infer<typeof PrivateReply>,
                ctx: Context,
            ) => {
                if (args.exit_code === 0) return save(ctx, { checked: true });
                let line = "";
                if (args.exit_code === 3) {
                    try {
                        const text = await read(
                            args.directory,
                            "content.result",
                        );
                        line = text.endsWith("\n") ? text.slice(0, -1) : text;
                    } catch {
                        line = "";
                    }
                }
                // The step writes one line that names no value; anything else is an error of the check.
                throw new Error(
                    /^tasks\.publish_secret_content: [^\n]{1,1000}$/.test(line)
                        ? line
                        : "tasks.publish_secret_unchecked",
                );
            },
        },
        base: {
            description:
                "Read the base statement of a task copy's publish and its merge at publish, or refuse an own workflow change or a real merge conflict with its paths",
            arguments: PrivateReply,
            execute: async (
                args: z.infer<typeof PrivateReply>,
                ctx: Context,
            ) => {
                const file = async (name: string) => {
                    try {
                        return await read(args.directory, "base/" + name);
                    } catch {
                        return "";
                    }
                };
                const { facts, merged, error_code } = baseFacts(args.exit_code, {
                    head: await file("head"),
                    base: await file("base"),
                    behind: await file("behind"),
                    overlap: await file("overlap"),
                    merge: await file("merge"),
                    // A file the read did not write is an error, never an empty one.
                    workflows: await read(args.directory, "base/workflows").catch(() => null),
                    merged: await read(args.directory, "base/merged").catch(() => null),
                });
                if (facts === null) throw new Error(error_code);
                // The merge at publish, or "": "Push the branch" pushes it instead of the candidate.
                return save(ctx, { stdout: JSON.stringify(facts), merged });
            },
        },
        written: {
            description:
                "Read the PR write reply and keep only its public publication metadata",
            arguments: PrivateReply.extend({
                input: PublishInput,
                number: z.number().int().min(0),
            }),
            execute: async (
                args: z.infer<typeof PrivateReply> & {
                    input: z.infer<typeof PublishInput>;
                    number: number;
                },
                ctx: Context,
            ) => {
                if (args.exit_code !== 0) {
                    throw new Error("tasks.publish_failed");
                }
                let number: number;
                let created: string | null;
                try {
                    const reply = z.object({
                        number: z.number().int().positive(),
                        created_at: z.string().datetime().nullish(),
                    }).parse(
                        JSON.parse(await read(args.directory, "pull.json")),
                    );
                    number = reply.number;
                    created = reply.created_at ?? null;
                } catch {
                    throw new Error("tasks.publish_failed");
                }
                if (args.number !== 0 && number !== args.number) {
                    throw new Error("tasks.publish_failed");
                }
                return save(ctx, {
                    stdout: JSON.stringify({
                        pull_request_number: number,
                        pull_request_url:
                            `https://github.com/${args.input.repository}/pull/${number}`,
                        // When GitHub opened the pull request: a created and an updated one both reply with it.
                        pull_request_created_at: created,
                    }),
                });
            },
        },
    },
};
