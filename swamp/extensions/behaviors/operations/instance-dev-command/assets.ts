import { z } from "npm:zod@4.4.3";
import { CompletePhases, completeResult } from "./complete_result.ts";
import { MigratePhases, migrateResult, writtenFailure } from "./migrate_result.ts";
import { containerFailure, CreateArgs, defaultContainerFailure, createResult, removeRun, routeDeferred, Step } from "./result.ts";
const ObjectValue = z.record(z.string(), z.unknown());
type Context = {
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};
/** The part a failed clone T3 step was running, from the copied `t3.json`, or null when the step left none. */
async function t3Part(path: string): Promise<"agent" | "t3" | "checkout" | "sessions" | null> {
    try {
        const parsed = z.object({t3_part: z.enum(["agent", "t3", "checkout", "sessions"])}).safeParse(JSON.parse(await Deno.readTextFile(path)));
        return parsed.success ? parsed.data.t3_part : null;
    } catch (error) {
        if (error instanceof Deno.errors.NotFound || error instanceof SyntaxError) return null;
        throw error;
    }
}

/** The run folder's result file, or null when no phase wrote one. */
async function resultFile(folder: string): Promise<unknown> {
    try {
        return JSON.parse(await Deno.readTextFile(folder + "/result.json"));
    } catch (error) {
        if (error instanceof Deno.errors.NotFound || error instanceof SyntaxError) return null;
        throw error;
    }
}

export const behavior = {
    type: "@nckrtl/instance-dev-command",
    version: "2026.11.15.2",
    resources: {
        result: { schema: ObjectValue, lifetime: "1d", garbageCollection: 10 },
    },
    methods: {
        create_result: {
            description:
                "Map the create phase steps to one public terminal receipt",
            arguments: CreateArgs,
            // Swamp validates `arguments` before it calls the method.
            execute: async (args: z.input<typeof CreateArgs>, ctx: Context) => {
                const container = args.intent.container as {clone?: unknown; club_key?: unknown} | null | undefined;
                // A nested-fleet default's container phases leave in the result file what its start did with the Club key.
                const file = container?.club_key != null && container.clone == null ? await resultFile(args.folder) : null;
                // A failed clone T3 step leaves the part it was running in t3.json, which clone-session-copy brings to `t3_file`.
                const t3_part = args.container?.t3 === "failed" ? await t3Part(args.t3_file ?? args.folder + "/t3.json") : null;
                const route_deferred = await routeDeferred(args.intent, args.route, args.folder);
                const value = createResult(args.intent, {...args, club_key_file: file, t3_part, route_deferred});
                const result = JSON.parse(value.stdout);
                if (!value.ready && ["container", "clone"].includes(result.failed_step)) {
                    // The phase owns redaction; preserve its public evidence in the Operation.
                    const written = file ?? await resultFile(args.folder);
                    const failure = result.failed_step === "container" ? defaultContainerFailure(written) : containerFailure(written);
                    if (failure !== null) value.stdout = JSON.stringify({...result, ...failure});
                }
                // A ready create's run folder goes here, so no cleanup step runs after the result (docs/cli/instance/container/create.mmd).
                // The step's outputs are the stored receipt, so `removed` is known before it is stored. A failed create keeps the folder:
                // stop-container reads it, and the cleanup step removes it after.
                const removed = value.ready ? await removeRun(args.folder, args.t3_file) : false;
                const done = {...value, removed};
                await ctx.writeResource("result", "create", done);
                return done;
            },
        },
        complete_result: {
            description: "Map the steps of a clone completion to one public terminal receipt",
            arguments: z.object({
                intent: ObjectValue,
                folder: z.string().regex(/^\/tmp\/orbit-ansible-[A-Za-z0-9-]+$/),
                ...CompletePhases.shape,
            }),
            execute: async (
                args: { intent: Record<string, unknown>; folder: string } & z.input<typeof CompletePhases>,
                ctx: Context,
            ) => {
                // A failed fleet step keeps no output; the fleet phase writes its code and why into the run's result file.
                const fleetFile = args.fleet === "failed" ? await resultFile(args.folder) : null;
                const value = completeResult(args.intent, CompletePhases.parse(args), fleetFile);
                await ctx.writeResource("result", "complete", value);
                return value;
            },
        },
        migrate_result: {
            description: "Map the steps of a move into a container to one public terminal receipt",
            arguments: z.object({
                intent: ObjectValue,
                folder: z.string().regex(/^\/tmp\/orbit-ansible-[A-Za-z0-9-]+$/),
                workspace: Step.nullable(),
                phases: MigratePhases,
                server: z.string().nullable().default(null),
            }),
            execute: async (
                args: {
                    intent: Record<string, unknown>;
                    folder: string;
                    workspace: z.infer<typeof Step> | null;
                    phases: z.infer<typeof MigratePhases>;
                    server?: string | null;
                },
                ctx: Context,
            ) => {
                const value = migrateResult(args.intent, args.phases, args.workspace, args.server ?? null, await writtenFailure(args.folder));
                await ctx.writeResource("result", "migrate", value);
                return value;
            },
        },
    },
};
