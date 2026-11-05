import { BatchPlanSchema, type Candidate } from "../_lib/maintenance/batch.ts";
import { plan } from "./_lib/batch.ts";
/** Composer maintenance in a supplied checkout, with no orchestration dependency. */
import {
    type Context,
    failureReceipts,
    failureResource,
    parse,
    write,
} from "../_lib/maintenance/context.ts";
import { z } from "npm:zod@4";
import {
    AdvisorySchema,
    audit,
    command,
    CommonSchema,
    MaintenanceFailure,
    object,
    places,
    reportJson,
} from "./_lib/io.ts";
import { staleExceptions, StaleSchema } from "./_lib/policy.ts";

export { MaintenanceFailure } from "./_lib/io.ts";

const CandidateSchema = z.strictObject({
    path: z.string(),
    manager: z.literal("composer"),
    package: z.string(),
    reasons: z.array(z.enum(["major", "security"])),
    advisories: z.array(AdvisorySchema),
    current: z.string().optional(),
    latest: z.string().optional(),
});
const DetectionSchema = z.strictObject({
    schema: z.literal(1),
    candidates: z.array(CandidateSchema),
    stale_exceptions: z.array(StaleSchema).optional(),
});

/** Swamp behavior: local commands and versioned schema 1 resources. */
export const behavior = {
    type: "@nckrtl/composer-maintenance",
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
                "Detect Composer locked majors and advisories without vendor",
            arguments: CommonSchema,
            execute: async (
                input: z.input<typeof CommonSchema>,
                ctx: Context,
            ) => {
                const args = parse(CommonSchema, input);
                const project = await places(args);
                const candidates = new Map<
                    string,
                    z.infer<typeof CandidateSchema>
                >();
                for (const place of project.places) {
                    const commandArgs = [
                        "outdated",
                        "--no-plugins",
                        "--no-scripts",
                        "--locked",
                        "--major-only",
                        "--format=json",
                    ];
                    const data = reportJson(
                        await command(
                            place.dir,
                            "composer",
                            commandArgs,
                            ctx.signal,
                        ),
                        commandArgs,
                    );
                    if (!Array.isArray(data.locked)) {
                        throw new MaintenanceFailure(
                            "Composer outdated has no locked field",
                        );
                    }
                    for (const entry of data.locked) {
                        const item = object(entry, "Composer outdated entry");
                        if (
                            typeof item.name !== "string" ||
                            typeof item.version !== "string" ||
                            typeof item.latest !== "string"
                        ) {
                            throw new MaintenanceFailure(
                                "Composer outdated entry is incomplete",
                            );
                        }
                        candidates.set(`${place.path}:composer:${item.name}`, {
                            path: place.path,
                            manager: "composer",
                            package: item.name,
                            reasons: ["major"],
                            advisories: [],
                            current: item.version,
                            latest: item.latest,
                        });
                    }
                }
                for (const place of project.places) {
                    for (
                        const [name, advisories] of Object.entries(
                            await audit(place.dir, ctx.signal),
                        )
                    ) {
                        if (!advisories.length) continue;
                        const key = `${place.path}:composer:${name}`;
                        const row = candidates.get(key) ?? {
                            path: place.path,
                            manager: "composer" as const,
                            package: name,
                            reasons: [],
                            advisories: [],
                        };
                        row.reasons.push("security");
                        row.advisories = advisories;
                        candidates.set(key, row);
                    }
                }
                const stale = await staleExceptions(
                    args.exceptions,
                    project.places,
                );
                return await write(ctx, "detection", DetectionSchema, {
                    schema: 1,
                    candidates: [...candidates.keys()].sort().map((key) =>
                        candidates.get(key)
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
