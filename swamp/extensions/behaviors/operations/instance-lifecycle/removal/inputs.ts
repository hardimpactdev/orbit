import { z } from "npm:zod@4.4.3";

const Id = z.number().int().positive();
const Intent = z.object({
    removal_id: z.string().uuid(),
    instance_id: Id,
    node_id: Id,
    project_id: Id,
    environment: z.enum(["development", "production"]),
    members: z.array(z.object({ instance_id: Id }).passthrough()).min(1),
    direct_destroy: z.object({
        runtime: z.object({
            processes: z.array(
                z.object({
                    process_id: Id,
                    runtime: z.enum(["systemd", "docker"]),
                    name: z.string(),
                }).passthrough(),
            ),
        }).passthrough(),
        teardown: z.array(z.object({
            instance_id: Id,
            checkout: z.string(),
            user: z.string(),
            steps: z.array(z.object({
                name: z.string(),
                sha256: z.string(),
                timeout_seconds: Id,
            })),
        })),
    }).passthrough(),
}).passthrough();
type Context = {
    extensionFile(name: string): string;
    writeResource(spec: string, name: string, data: unknown): Promise<unknown>;
};
const Capture = z.object({
    instance_id: Id,
    release: z.string().nullable(),
    source: z.string(),
}).passthrough();
const Inspected = z.object({ members: z.array(Capture).min(1) });
const PublicData = z.record(z.string(), z.unknown());

/** No operational commands or Gateway calls: native commands own every effect. */
export const behavior = {
    type: "@nckrtl/instance-removal-inputs",
    version: "2026.10.31.1",
    resources: {
        intent: {
            schema: PublicData,
            lifetime: "workflow",
            garbageCollection: 1,
        },
        capture: {
            schema: PublicData,
            lifetime: "workflow",
            garbageCollection: 1,
        },
    },
    methods: {
        read: {
            arguments: z.object({ intent: Intent }),
            description:
                "Serialize complete admitted public intent for command env",
            execute: async (
                args: { intent: z.infer<typeof Intent> },
                ctx: Context,
            ) => {
                const value = {
                    intent_json: JSON.stringify(args.intent),
                    scripts: Object.fromEntries(
                        [
                            "inspect_sources",
                            "quarantine",
                            "delete_tree",
                            "delete_worktree_metadata",
                            "delete_account",
                            "delete_marker",
                            "stop_schedule",
                            "stop_schedule_service",
                            "stop_process",
                            "teardown",
                            "verify_source",
                            "check_publication",
                        ].map((
                            name,
                        ) => [name, ctx.extensionFile(name + ".py")]),
                    ),
                    library: ctx.extensionFile("identity.py").replace(
                        /\/identity.py$/,
                        "",
                    ),
                };
                await ctx.writeResource("intent", "intent", value);
                return value;
            },
        },
        capture: {
            arguments: z.object({ intent: Intent, stdout: z.string() }),
            description:
                "Shape native source capture and per-member action inputs",
            execute: async (
                args: { intent: z.infer<typeof Intent>; stdout: string },
                ctx: Context,
            ) => {
                const inspection = Inspected.parse(
                    JSON.parse(args.stdout).source_inspection,
                );
                const dispositions = z.array(z.object({
                    instance_id: Id,
                    teardown_required: z.boolean(),
                })).parse(JSON.parse(args.stdout).source_dispositions);
                const teardownRequired = new Map(
                    dispositions.map((
                        row,
                    ) => [row.instance_id, row.teardown_required]),
                );
                const captured = new Map(
                    inspection.members.map((row) => [row.instance_id, row]),
                );
                if (
                    captured.size !== args.intent.members.length ||
                    teardownRequired.size !== captured.size
                ) {
                    throw new Error("instance.removal_conflict");
                }
                const members = args.intent.members.map((member) => {
                    const capture = captured.get(member.instance_id);
                    if (!capture) throw new Error("instance.removal_conflict");
                    return {
                        instance_id: member.instance_id,
                        publication_required: args.intent.force !== true &&
                            member.failed_creation !== true &&
                            capture.source_commit !== "",
                        input_json: JSON.stringify({
                            intent: {
                                ...args.intent,
                                captured_sources: inspection,
                            },
                            member,
                            capture,
                        }),
                    };
                });
                const teardown = args.intent.direct_destroy.teardown.flatMap(
                    (member) => {
                        const capture = captured.get(member.instance_id);
                        if (!capture) {
                            throw new Error("instance.removal_conflict");
                        }
                        if (!teardownRequired.has(member.instance_id)) {
                            throw new Error("instance.removal_conflict");
                        }
                        if (
                            capture.release === null ||
                            !teardownRequired.get(member.instance_id)
                        ) return [];
                        let checkout = member.checkout;
                        const production =
                            args.intent.environment === "production";
                        if (production) {
                            const current = capture.source + "/current";
                            if (
                                checkout !== current &&
                                !checkout.startsWith(current + "/")
                            ) {
                                throw new Error("instance.removal_conflict");
                            }
                            checkout = capture.release +
                                checkout.slice(current.length);
                        }
                        return member.steps.map((step, index) => ({
                            instance_id: member.instance_id,
                            index,
                            secret_field:
                                `teardown_${member.instance_id}_${index}`,
                            input_json: JSON.stringify({
                                ...member,
                                steps: undefined,
                                checkout,
                                step,
                                project_id: args.intent.project_id,
                                production_home: production
                                    ? capture.source
                                    : null,
                                environment_file: production
                                    ? capture.source + "/.env"
                                    : member.checkout + "/.env",
                            }),
                        }));
                    },
                );
                const value = {
                    source_inspection: inspection,
                    members,
                    worktrees: members.filter((row) =>
                        args.intent.members.find((member) =>
                            member.instance_id === row.instance_id
                        )?.source_layout === "worktree"
                    ),
                    checkouts: members.filter((row) =>
                        args.intent.members.find((member) =>
                            member.instance_id === row.instance_id
                        )?.source_layout !== "worktree"
                    ),
                    teardown,
                    process_stops: args.intent.direct_destroy.runtime.processes
                        .flatMap((process) =>
                            process.runtime === "docker"
                                ? [
                                    "",
                                    "-candidate",
                                    "-rollback-running",
                                    "-rollback-stopped",
                                ].map((suffix) => ({
                                    ...process,
                                    name: process.name + suffix,
                                }))
                                : [process]
                        ),
                };
                await ctx.writeResource("capture", "capture", value);
                return value;
            },
        },
    },
};
