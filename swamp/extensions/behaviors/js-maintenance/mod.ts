import { BatchPlanSchema, type Candidate } from "../_lib/maintenance/batch.ts";
import { plan } from "./_lib/batch.ts";
/** JavaScript maintenance in a supplied checkout; no Composer or orchestrator is needed. */
import { z } from "npm:zod@4";
import {
    type Context,
    failureReceipts,
    failureResource,
    parse,
    write,
} from "../_lib/maintenance/context.ts";
import {
    AdvisorySchema,
    CommonSchema,
    places,
} from "../_lib/maintenance/io.ts";
import { staleExceptions, StaleSchema } from "./_lib/policy.ts";
import { audit, majors, published } from "./_lib/reports.ts";
import { coupled, workspace } from "./_lib/workspace.ts";
/** A maintenance error with the command-compatible exit code. */
export { MaintenanceFailure } from "../_lib/maintenance/io.ts";
const CandidateSchema = z.strictObject({
    path: z.string(),
    manager: z.literal("js"),
    package: z.string(),
    reasons: z.array(z.enum(["major", "security"])),
    advisories: z.array(
        AdvisorySchema.extend({ severity: z.string().nullable().optional() }),
    ),
    current: z.string().optional(),
    latest: z.string().optional(),
    align: z.literal("expo-install-fix").optional(),
});
const DetectionSchema = z.strictObject({
    schema: z.literal(1),
    candidates: z.array(CandidateSchema),
    stale_exceptions: z.array(StaleSchema).optional(),
});
async function project(args: z.infer<typeof CommonSchema>) {
    const result = await places(args, "package.json");
    const workspaces = [];
    // Parse every manager and manifest before any command can mutate a path.
    for (const place of result.places) workspaces.push(await workspace(place));
    return { ...result, workspaces };
}
/** Local commands with versioned schema 1 resources, matching Composer maintenance. */
export const behavior = {
    type: "@nckrtl/js-maintenance",
    version: "2026.11.02.1",
    globalArguments: z.strictObject({}),
    resources: {
        failure: failureResource,
        detection: {
            description: "Locked majors, advisories and due exceptions",
            schema: DetectionSchema,
            lifetime: "30d",
            garbageCollection: 20,
        },
        plan: {
            description: "Eligible batch targets and blocked findings",
            schema: BatchPlanSchema,
            lifetime: "infinite",
            garbageCollection: 10,
        },
    },
    methods: failureReceipts({
        detect: {
            description:
                "Detect direct majors and real security advisories from npm or Bun",
            arguments: CommonSchema,
            execute: async (
                input: z.input<typeof CommonSchema>,
                ctx: Context,
            ) => {
                const args = parse(CommonSchema, input),
                    p = await project(args);
                const candidates = new Map<
                    string,
                    z.infer<typeof CandidateSchema>
                >();
                for (const js of p.workspaces) {
                    for (
                        const row of await majors(
                            { ...js, vitePlus: false },
                            ctx.signal,
                        )
                    ) {
                        if (
                            js.expo && coupled(row.name) && row.name !== "expo"
                        ) continue;
                        candidates.set(`${js.path}:js:${row.name}`, {
                            path: js.path,
                            manager: "js",
                            package: row.name,
                            reasons: ["major"],
                            advisories: [],
                            current: row.current,
                            latest: row.latest,
                            ...(js.expo && row.name === "expo"
                                ? { align: "expo-install-fix" as const }
                                : {}),
                        });
                    }
                }
                for (const js of p.workspaces) {
                    for (
                        const [name, advisories] of Object.entries(
                            published(
                                await audit(
                                    { ...js, vitePlus: false },
                                    ctx.signal,
                                    true,
                                ),
                            ),
                        )
                    ) {
                        if (!advisories.length) continue;
                        const key = `${js.path}:js:${name}`;
                        const row = candidates.get(key) ??
                            {
                                path: js.path,
                                manager: "js" as const,
                                package: name,
                                reasons: [],
                                advisories: [],
                            };
                        row.reasons.push("security");
                        row.advisories = advisories;
                        candidates.set(key, row);
                    }
                }
                const stale = await staleExceptions(args.exceptions, p.places);
                return await write(ctx, "detection", DetectionSchema, {
                    schema: 1,
                    candidates: [...candidates.keys()].sort().map((k) =>
                        candidates.get(k)
                    ),
                    ...(stale.length ? { stale_exceptions: stale } : {}),
                });
            },
        },
        plan: {
            description:
                "Resolve all eligible targets in a disposable checkout without changing the source",
            arguments: CommonSchema.extend({
                candidates: z.array(CandidateSchema),
            }),
            execute: async (
                input: z.input<typeof CommonSchema> & {
                    candidates: Candidate[];
                },
                ctx: Context,
            ) => {
                const { candidates, ...common } = input;
                return await write(
                    ctx,
                    "plan",
                    BatchPlanSchema,
                    await plan(
                        parse(CommonSchema, common),
                        candidates,
                        ctx.signal,
                    ),
                );
            },
        },
    }),
};
