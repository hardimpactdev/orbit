import { z } from "npm:zod@4.4.3";
const Key = z.string().regex(/^[A-Za-z0-9+/]{43}=$/);
const Snapshot = z.object({
    directory: z.string().min(1),
    present: z.array(z.enum(["orbit.conf", "orbit.key", "orbit.dns-link"])),
    active: z.boolean(),
    enabled: z.string(),
    resolver: z.record(z.string(), z.array(z.string())),
    recorded_resolver: z.object({
        link: z.string().regex(/^[A-Za-z0-9_.:+-]+$/),
        settings: z.record(z.string(), z.array(z.string())),
    }).strict().nullable(),
}).strict();
const Peer = z.object({
    server_public_key: Key,
    endpoint: z.string().regex(/^[A-Za-z0-9.:[\]-]+$/),
    address: z.string(),
    subnet: z.string(),
    dns_server: z.ipv4(),
    domains: z.array(z.string().regex(/^[A-Za-z0-9.-]+$/)),
    operator_dns: z.string().nullable(),
    retain: z.boolean(),
}).strict();
const Args = z.object({
    action: z.enum([
        "snapshot",
        "prepare_key",
        "publish",
        "record_dns",
        "restore",
        "cleanup",
    ]),
    input: z.object({
        peer: Peer.optional(),
        existing_key: Key.nullable().optional(),
        snapshot: Snapshot.optional(),
    }).strict(),
});
const Output = z.object({ script_body: z.string() });
const State = z.union([
    Snapshot,
    z.object({ public_key: Key }).strict(),
    z.object({ published: z.literal(true) }).strict(),
    z.object({ dns_recorded: z.literal(true) }).strict(),
    z.object({ restored: z.literal(true) }).strict(),
    z.object({ cleaned: z.literal(true) }).strict(),
]);
type Context = {
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};
export const behavior = {
    type: "@nckrtl/node-peer-command",
    version: "2026.10.31.1",
    resources: {
        script: { schema: Output, lifetime: "1d", garbageCollection: 10 },
        state: { schema: State, lifetime: "1d", garbageCollection: 10 },
        terminal: {
            schema: z.object({ stdout: z.string() }),
            lifetime: "1d",
            garbageCollection: 10,
        },
    },
    methods: {
        decode: {
            description:
                "Read a typed public result from the preceding official SSH step",
            arguments: z.object({ stdout: z.string() }),
            execute: async (args: { stdout: string }, ctx: Context) => {
                const state = State.parse(JSON.parse(args.stdout));
                await ctx.writeResource("state", "state", state);
                return state;
            },
        },
        retarget_result: {
            description:
                "Expose the completed native endpoint and compensation result",
            arguments: z.object({
                success: z.boolean(),
                restored: z.boolean(),
                public_key: Key.nullable(),
            }),
            execute: async (
                args: {
                    success: boolean;
                    restored: boolean;
                    public_key: string | null;
                },
                ctx: Context,
            ) => {
                const value = {
                    retargeted: args.success,
                    restored: args.restored,
                    ...(args.success && args.public_key !== null
                        ? { public_key: args.public_key }
                        : {}),
                };
                const result = { stdout: JSON.stringify(value) };
                await ctx.writeResource("terminal", "terminal", result);
                return result;
            },
        },
        read: {
            description:
                "Resolve one shipped peer action with its public input",
            arguments: Args,
            execute: async (args: z.infer<typeof Args>, ctx: {
                extensionFile(name: string): string;
                writeResource(
                    spec: string,
                    name: string,
                    value: unknown,
                ): Promise<unknown>;
            }) => {
                const common = await Deno.readTextFile(
                    ctx.extensionFile("scripts/common.py"),
                );
                const action = (await Deno.readTextFile(
                    ctx.extensionFile("scripts/" + args.action + ".py"),
                )).replace(/^from common import .*\n/m, "");
                const result = {
                    script_body: common.replace(
                        'ACTION_JSON = ""',
                        "ACTION_JSON = " +
                            JSON.stringify(JSON.stringify(args.input)),
                    ) + "\n" + action,
                };
                await ctx.writeResource("script", "script", result);
                return result;
            },
        },
    },
};
