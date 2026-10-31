import { z } from "npm:zod@4.4.3";
const Name = z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/);
const Path = z.string().min(1).max(4096).startsWith("/").refine((v) =>
    !/[\0\r\n]/.test(v) && !v.split("/").includes("..")
);
const Text = z.string().max(1048576).refine((v) => !v.includes("\0"));
export const RuntimeInput = z.object({
    user: Name,
    version: z.string().regex(/^[0-9]+\.[0-9]+$/),
    marker: z.string().min(1).max(256),
    mode: z.literal("monitor"),
    home: Path,
    application: Path,
    initial_application: Path,
    configuration: z.object({
        main: Text,
        pool: Text,
        initial_pool: Text,
        local_defaults: Text,
        master_ini: Text,
        unit: Text,
    }).strict(),
}).strict();
const Args = z.object({
    run_id: z.uuid(),
    target_node_id: z.number().int().positive().refine((id) =>
        id !== 1 && id !== 18
    ),
    runtime: RuntimeInput,
}).strict();
const names = [
    "stage",
    "validate",
    "publish",
    "reload",
    "confirm",
    "restore",
    "reload_restored",
    "finish_restore",
    "inspect",
    "cleanup",
] as const;
export function monitorAssets(
    raw: unknown,
    extensionFile: (name: string) => string,
) {
    const args = Args.parse(raw);
    const input_json = JSON.stringify({
        run_id: args.run_id,
        runtime: args.runtime,
    });
    if (new TextEncoder().encode(input_json).length > 65536) {
        throw new Error("php.monitor_input_too_large");
    }
    const scripts = Object.fromEntries(
        names.map((
            name,
        ) => [
            name,
            extensionFile(
                `scripts/${name === "inspect" ? "inspect_pool" : name}.py`,
            ),
        ]),
    );
    return { input_json, scripts };
}
const Assets = z.object({
    input_json: z.string(),
    scripts: z.record(z.string(), z.string()),
});
const Observation = z.object({
    present: z.boolean(),
    changed: z.boolean(),
    active: z.boolean(),
}).strict();
type Context = {
    extensionFile: (name: string) => string;
    writeResource: (
        spec: string,
        name: string,
        value: unknown,
    ) => Promise<unknown>;
};
export const behavior = {
    type: "@nckrtl/php-monitor-command",
    version: "2026.10.31.1",
    resources: {
        assets: { schema: Assets, lifetime: "infinite", garbageCollection: 10 },
        observation: {
            schema: Observation,
            lifetime: "infinite",
            garbageCollection: 10,
        },
    },
    methods: {
        assets: {
            description:
                "Shape public monitor intent and resolve shipped action scripts",
            arguments: Args,
            execute: async (raw: unknown, ctx: Context) => {
                const output = monitorAssets(raw, ctx.extensionFile);
                await ctx.writeResource("assets", "assets", output);
                return output;
            },
        },
        observe: {
            description: "Shape the public staged runtime observation",
            arguments: z.object({ stdout: z.string() }).strict(),
            execute: async (args: { stdout: string }, ctx: Context) => {
                const output = Observation.parse(JSON.parse(args.stdout));
                await ctx.writeResource("observation", "observation", output);
                return output;
            },
        },
    },
};
