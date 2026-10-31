import { z } from "npm:zod@4.4.3";
const User = z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/);
const Inputs = {
    account: z.object({ user: User }).strict(),
    caddy_package: z.object({
        release: z.string().regex(/^\d+\.\d+\.\d+$/),
        minimum_version: z.string().regex(/^\d+\.\d+\.\d+$/),
        amd64_sha512: z.string().regex(/^[a-f0-9]{128}$/),
    }).strict(),
};
const Args = z.object({
    action: z.enum(["account", "caddy_package"]),
    input: z.union([Inputs.account, Inputs.caddy_package]),
}).strict();
const Account = z.object({
    user: User,
    group: User,
    home: z.string().startsWith("/"),
}).strict();
const Output = z.object({ script_body: z.string() }).strict();
const Result = z.union([
    Account,
    z.object({ caddy_installed: z.literal(true) }).strict(),
]);
export const behavior = {
    type: "@nckrtl/node-role-command",
    version: "2026.10.31.1",
    resources: {
        assets: { schema: Output, lifetime: "1d", garbageCollection: 10 },
        result: { schema: Result, lifetime: "1d", garbageCollection: 10 },
    },
    methods: {
        read: {
            description: "Read one public role action for official SSH",
            arguments: Args,
            execute: async (args: z.infer<typeof Args>, ctx: {
                extensionFile(name: string): string;
                writeResource(
                    spec: string,
                    name: string,
                    value: unknown,
                ): Promise<unknown>;
            }) => {
                const input = Args.parse(args);
                const value = Inputs[input.action].parse(input.input);
                const source = await Deno.readTextFile(
                    ctx.extensionFile("scripts/" + input.action + ".py"),
                );
                const result = Output.parse({
                    script_body: source.replace(
                        "ROLE_INPUT = None",
                        "ROLE_INPUT = json.loads(" +
                            JSON.stringify(JSON.stringify(value)) + ")",
                    ),
                });
                await ctx.writeResource("assets", "assets", result);
                return result;
            },
        },
        decode: {
            description: "Decode the public role action output",
            arguments: z.object({ stdout: z.string() }).strict(),
            execute: async (
                args: { stdout: string },
                ctx: {
                    writeResource(
                        spec: string,
                        name: string,
                        value: unknown,
                    ): Promise<unknown>;
                },
            ) => {
                const result = Result.parse(JSON.parse(args.stdout));
                await ctx.writeResource("result", "result", result);
                return result;
            },
        },
    },
};
