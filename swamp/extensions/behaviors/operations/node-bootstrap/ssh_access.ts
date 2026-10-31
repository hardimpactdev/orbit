import { z } from "npm:zod@4.4.3";

const Input = z.object({
    user: z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/).refine((user) =>
        user !== "root"
    ),
    public_key: z.string().min(1).max(16384).regex(/^[^\r\n\0]+$/),
}).strict();
const Assets = z.object({ script_body: z.string().max(1048576) }).strict();
const Result = z.object({ stdout: z.string() }).strict();
const Verification = z.object({
    verification: z.object({ exitCode: z.literal(0) }),
}).strict();
type Context = {
    extensionFile(name: string): string;
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};

/** Public tracked-key policy assets and bounded native result. Official SSH owns execution. */
export const behavior = {
    type: "@nckrtl/node-ssh-access",
    version: "2026.10.31.1",
    resources: {
        assets: { schema: Assets, lifetime: "1d", garbageCollection: 10 },
        result: { schema: Result, lifetime: "1d", garbageCollection: 10 },
    },
    methods: {
        read: {
            description:
                "Read the tracked managed-account authorized-key action",
            arguments: Input,
            execute: async (args: z.infer<typeof Input>, ctx: Context) => {
                const input = Input.parse(args);
                const source = await Deno.readTextFile(
                    ctx.extensionFile("scripts/bootstrap_authorized_key.py"),
                );
                const result = Assets.parse({
                    script_body: source.replace(
                        "BOOTSTRAP_INPUT = None",
                        "BOOTSTRAP_INPUT = json.loads(" +
                            JSON.stringify(JSON.stringify(input)) + ")",
                    ),
                });
                await ctx.writeResource("assets", "assets", result);
                return result;
            },
        },
        result: {
            description:
                "Record successful access with the approved 209 identity",
            arguments: Verification,
            execute: async (
                args: z.infer<typeof Verification>,
                ctx: Context,
            ) => {
                Verification.parse(args);
                const result = {
                    stdout: JSON.stringify({ ssh_access_ready: true }),
                };
                await ctx.writeResource("result", "result", result);
                return result;
            },
        },
    },
};
