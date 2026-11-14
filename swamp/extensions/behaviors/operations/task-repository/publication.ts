import { z } from "npm:zod@4.4.3";
import {
    imported,
    PublishInput,
    pullPlan,
    pushed,
    run,
    verify,
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
    version: "2026.11.14.1",
    resources: {
        public: {
            description: "Public publication observations",
            schema: z.record(z.string(), z.unknown()),
            lifetime: "infinite",
            garbageCollection: 10,
        },
    },
    methods: {
        verify: {
            description:
                "Compare the observed workspace with the admitted candidate",
            arguments: Observed,
            execute: (args: z.infer<typeof Observed>, ctx: Context) =>
                save(
                    ctx,
                    requireSuccess(
                        verify(args),
                        "admitted",
                        "tasks.repository_execution_unknown",
                    ),
                ),
        },
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
        pull: {
            description:
                "Plan one PR write and materialize its unchanged JSON input",
            arguments: PrivateReply.extend({ input: PublishInput }),
            execute: async (
                args: z.infer<typeof PrivateReply> & {
                    input: z.infer<typeof PublishInput>;
                },
                ctx: Context,
            ) => {
                const plan = requireResult(
                    pullPlan({
                        input: args.input,
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
                try {
                    number =
                        z.object({ number: z.number().int().positive() }).parse(
                            JSON.parse(await read(args.directory, "pull.json")),
                        ).number;
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
                    }),
                });
            },
        },
    },
};
