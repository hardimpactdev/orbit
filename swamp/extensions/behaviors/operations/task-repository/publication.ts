import { z } from "npm:zod@4.4.3";
import { finish, prepare, verify } from "../tasks/publish.ts";

type Context = {
    extensionFile(name: string): string;
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};
const Args = z.record(z.string(), z.unknown());
const data = async (ctx: Context, value: unknown) => ({
    dataHandles: [await ctx.writeResource("public", "output", value)],
});

/** Pure input/result shaping and script paths; no execution or credentials. */
export const behavior = {
    type: "@nckrtl/task-publication-data",
    version: "2026.10.31.1",
    resources: {
        public: {
            description: "Public publication inputs and observations",
            schema: z.record(z.string(), z.unknown()),
            lifetime: "infinite",
            garbageCollection: 10,
        },
    },
    methods: {
        prepare: {
            description:
                "Resolve shipped actions and serialize the public intent",
            arguments: Args,
            execute: (args: Record<string, unknown>, ctx: Context) =>
                data(ctx, {
                    ...prepare(args),
                    directory: ctx.extensionFile("scripts/publish_export.py")
                        .replace(/\/[^/]+$/, ""),
                }),
        },
        verify: {
            description:
                "Compare the observed workspace to the admitted candidate",
            arguments: Args,
            execute: (args: unknown, ctx: Context) => data(ctx, verify(args)),
        },
        decode: {
            description:
                "Decode a public action observation for native branching",
            arguments: z.object({ value: z.string() }),
            execute: (args: { value: string }, ctx: Context) =>
                data(ctx, Args.parse(JSON.parse(args.value))),
        },
        result: {
            description: "Shape the public terminal publication result",
            arguments: Args,
            execute: (args: unknown, ctx: Context) => data(ctx, finish(args)),
        },
    },
};
