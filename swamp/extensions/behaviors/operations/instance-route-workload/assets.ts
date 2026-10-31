import { z } from "npm:zod@4.4.3";
const Path = z.string().startsWith("/");
const User = z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/);
const Relative = z.string().refine((s) =>
    !s.startsWith("/") &&
    s.split("/").every((part) => part !== "." && part !== "..")
);
const Access = z.object({
    user: User,
    sites: z.array(
        z.object({ checkout: Path, relative_root: Relative, application: Path })
            .strict(),
    ),
}).strict();
const Served = z.object({
    user: User,
    home: Path,
    default_web_root: Relative,
    default_directory: Relative,
    served: z.array(
        z.object({ web_root: Relative, directory: Relative }).strict(),
    ),
}).strict();
const Args = z.object({
    action: z.enum(["source_access", "served_roots"]),
    input: z.union([Access, Served]),
}).strict();
const Output = z.object({ script_body: z.string() });
const Ready = z.object({ ready: z.literal(true) });
type Context = {
    extensionFile(path: string): string;
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};
export const behavior = {
    type: "@nckrtl/instance-route-workload",
    version: "2026.10.31.1",
    resources: {
        assets: { schema: Output, lifetime: "infinite", garbageCollection: 10 },
        result: {
            schema: Ready.passthrough(),
            lifetime: "infinite",
            garbageCollection: 10,
        },
    },
    methods: {
        verifyPool: {
            description:
                "Verify the official file action observed the captured pool",
            arguments: z.object({
                expected: z.string(),
                state: z.object({
                    status: z.enum(["applied", "compliant"]),
                    current: z.object({
                        exists: z.literal(true),
                        contentSha256: z.string(),
                    }),
                }).passthrough(),
            }),
            execute: async (
                args: {
                    expected: string;
                    state: { current: { contentSha256: string } };
                },
                ctx: Context,
            ) => {
                if (args.state.current.contentSha256 !== args.expected) {
                    throw new Error("php.pool_unproven");
                }
                const result = { ready: true };
                await ctx.writeResource("result", "result", result);
                return result;
            },
        },
        prepared: {
            description:
                "Require every admitted workload contribution, including empty branches",
            arguments: z.object({
                required: z.record(z.string(), z.number().int().nonnegative()),
                outcomes: z.record(
                    z.string(),
                    z.object({ status: z.string() }).passthrough(),
                ),
            }),
            execute: async (
                args: {
                    required: Record<string, number>;
                    outcomes: Record<string, { status: string }>;
                },
                ctx: Context,
            ) => {
                for (const [name, count] of Object.entries(args.required)) {
                    for (let i = 0; i < count; i++) {
                        if (
                            args.outcomes[`${name}-${i}`]?.status !==
                                "succeeded"
                        ) {
                            throw new Error("route.workload_incomplete");
                        }
                    }
                }
                const result = { ready: true };
                await ctx.writeResource("result", "result", result);
                return result;
            },
        },
        read: {
            description:
                "Resolve one public workload action for the official SSH script method",
            arguments: Args,
            execute: async (raw: z.infer<typeof Args>, ctx: Context) => {
                const args = Args.parse(raw);
                const input =
                    (args.action === "source_access" ? Access : Served).parse(
                        args.input,
                    );
                const source = await Deno.readTextFile(
                    ctx.extensionFile(`scripts/${args.action}.py`),
                );
                const output = {
                    script_body: source.replace(
                        "WORKLOAD_INPUT = None",
                        "WORKLOAD_INPUT = json.loads(" +
                            JSON.stringify(JSON.stringify(input)) + ")",
                    ),
                };
                await ctx.writeResource("assets", "assets", output);
                return output;
            },
        },
        decode: {
            description: "Decode one workload action public completion",
            arguments: z.object({ stdout: z.string() }),
            execute: async (args: { stdout: string }, ctx: Context) => {
                const result = Ready.passthrough().parse(
                    JSON.parse(args.stdout),
                );
                await ctx.writeResource("result", "result", result);
                return result;
            },
        },
    },
};
