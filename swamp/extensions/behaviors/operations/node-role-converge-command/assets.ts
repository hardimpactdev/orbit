import { z } from "npm:zod@4.4.3";

const Account = z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/);
const Path = z.string().startsWith("/").max(4096).refine((value) =>
    !value.includes("\0") &&
    !value.split("/").some((part) => part === "." || part === "..")
);
const Inputs = {
    storage: z.object({ root: Path, user: Account, group: Account }).strict(),
    directory: z.object({
        path: z.enum([
            "/dev/shm/orbit/hibernation",
            "/data/caddy/orbit/hibernation",
        ]),
    }).strict(),
    ci_resource: z.object({
        resource: z.enum(["pool", "project", "acl", "network", "profile"]),
    }).strict(),
};
const Args = z.object({
    action: z.enum(["storage", "directory", "ci_resource"]),
    input: z.record(z.string(), z.unknown()),
}).strict();
const Assets = z.object({ script_body: z.string() }).strict();
const Result = z.object({ ready: z.literal(true), changed: z.boolean() })
    .strict();
type Context = {
    extensionFile(name: string): string;
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};

/** Read and shape public assets only. All effects run in official SSH steps. */
export const behavior = {
    type: "@nckrtl/node-role-converge-command",
    version: "2026.10.31.1",
    resources: {
        assets: { schema: Assets, lifetime: "1d", garbageCollection: 10 },
        result: { schema: Result, lifetime: "1d", garbageCollection: 10 },
    },
    methods: {
        read: {
            description: "Read one public role baseline action",
            arguments: Args,
            execute: async (raw: z.infer<typeof Args>, ctx: Context) => {
                const args = Args.parse(raw);
                const input = Inputs[args.action].parse(args.input);
                const source = await Deno.readTextFile(
                    ctx.extensionFile("scripts/" + args.action + ".py"),
                );
                const result = Assets.parse({
                    script_body: source.replace(
                        "ROLE_INPUT = None",
                        "ROLE_INPUT = json.loads(" +
                            JSON.stringify(JSON.stringify(input)) + ")",
                    ),
                });
                await ctx.writeResource("assets", "assets", result);
                return result;
            },
        },
        decode: {
            description: "Decode the public baseline action result",
            arguments: z.object({ stdout: z.string() }).strict(),
            execute: async (args: { stdout: string }, ctx: Context) => {
                const result = Result.parse(JSON.parse(args.stdout));
                await ctx.writeResource("result", "result", result);
                return result;
            },
        },
    },
};
