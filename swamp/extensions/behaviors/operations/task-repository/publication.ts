import { z } from "npm:zod@4.4.3";
import {
    finish,
    flatten,
    imported,
    prepare,
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
const Observed = z.object({ input: Args, steps: Args });
const data = async (ctx: Context, value: unknown) => ({
    dataHandles: [await ctx.writeResource("public", "output", value)],
});

/** Pure input and result shaping for tasks.publish; no execution, HTTP or credentials. */
export const behavior = {
    type: "@nckrtl/task-publication-data",
    version: "2026.11.02.2",
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
            description: "Build the push target and PR lookup from the intent",
            arguments: Args,
            execute: (args: Record<string, unknown>, ctx: Context) =>
                data(ctx, prepare(args)),
        },
        verify: {
            description:
                "Compare the observed workspace to the admitted candidate",
            arguments: Observed,
            execute: (args: unknown, ctx: Context) => data(ctx, verify(args)),
        },
        imported: {
            description: "Compare the imported bundle to the admitted commit",
            arguments: Observed,
            execute: (args: z.infer<typeof Observed>, ctx: Context) => {
                const input = PublishInput.parse(args.input);
                return data(
                    ctx,
                    imported(
                        run(args.steps, "import"),
                        input.commit,
                        input.tree,
                    ),
                );
            },
        },
        pushed: {
            description: "Map the push without force to its public outcome",
            arguments: Observed,
            execute: (args: z.infer<typeof Observed>, ctx: Context) =>
                data(ctx, pushed(run(args.steps, "push"))),
        },
        pull: {
            description: "Plan one PR update or creation from the open lookup",
            arguments: Observed,
            execute: (args: unknown, ctx: Context) => data(ctx, pullPlan(args)),
        },
        result: {
            description:
                "Shape the public terminal publication result from the parent run's steps and this step's own",
            arguments: Observed.extend({ parent: Args.default({}) }),
            execute: (
                args: z.infer<typeof Observed> & { parent: Record<string, unknown> },
                ctx: Context,
            ) =>
                data(
                    ctx,
                    finish({
                        input: args.input,
                        steps: { ...flatten(args.parent), ...args.steps },
                    }),
                ),
        },
    },
};
