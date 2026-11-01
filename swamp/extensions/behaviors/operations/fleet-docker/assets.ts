import { z } from "npm:zod@4.4.3";
const Id = z.string().regex(/^[1-9][0-9]*$/);
const Version = z.string().max(256).regex(/^[0-9][0-9A-Za-z.+:~_-]*$/);
export const DockerMigrationInput = z.object({
    recipe: z.string().min(1).max(4096).refine((value) =>
        [...value].every((character) =>
            character.codePointAt(0)! >= 32 && character.codePointAt(0)! !== 127
        )
    ),
    snapshot: z.string().regex(/^[0-9]{8}T[0-9]{6}Z$/),
    architecture: z.literal("amd64"),
    docker_version: Version,
    containerd_version: Version,
    process_ids: z.array(Id).max(10000),
    process_states: z.record(Id, z.enum(["running", "stopped"])),
    process_specs: z.record(
        Id,
        z.object({
            name: z.string().min(1).max(255),
            hash: z.string().regex(/^[a-f0-9]{64}$/),
            volumes: z.array(
                z.object({ source: z.string(), target: z.string() })
                    .passthrough(),
            ),
        }).strict(),
    ),
    fallback: z.boolean().default(false),
}).strict().superRefine((input, ctx) => {
    if (
        new Set(input.process_ids).size !== input.process_ids.length ||
        input.process_ids.some((id) =>
            !(id in input.process_states) || !(id in input.process_specs)
        ) ||
        [
            ...Object.keys(input.process_states),
            ...Object.keys(input.process_specs),
        ]
            .some((id) => !input.process_ids.includes(id))
    ) {
        ctx.addIssue({
            code: "custom",
            message: "Process ownership differs from admission",
        });
    }
});
export type DockerMigrationInput = z.infer<typeof DockerMigrationInput>;

const ObjectInput = z.record(z.string(), z.unknown());
const Args = z.object({
    run_id: z.uuid(),
    mode: z.enum(["plan", "migrate", "restore-policies"]),
    migration: DockerMigrationInput,
}).strict();
const Assets = z.object({ scripts: z.string(), input_json: z.string() });
const Stdout = z.object({ stdout: z.string() });
type Context = {
    extensionFile(path: string): string;
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};
export function terminal(steps: Record<string, unknown>, mode: string) {
    const output = (name: string) =>
        ObjectInput.parse(ObjectInput.parse(steps[name] ?? {}).outputs ?? {});
    const observed = output(mode === "plan" ? "inventory" : "report");
    const raw = observed.stdout
        ? ObjectInput.parse(JSON.parse(String(observed.stdout)))
        : {};
    const failed = Object.entries(steps).find(([name, step]) =>
        ObjectInput.parse(step).status === "failed" &&
        !(raw.fallback === true &&
            (name === "engine-start" || name.startsWith("probe-start-")))
    );
    const result = {
        healthy: false,
        fallback: false,
        containers: [],
        process_ids: [],
        ...raw,
    };
    if (mode === "plan") {
        Object.assign(result, {
            planned: true,
            containers: [],
            healthy: raw.healthy === true,
        });
    }
    if (failed || result.healthy !== true) {
        Object.assign(result, {
            healthy: false,
            error_code: "docker.migration_failed",
            stage: failed?.[0] ?? "report",
            resumable: true,
        });
    }
    const safe = Object.fromEntries(
        Object.entries(result).filter(([key]) =>
            [
                "healthy",
                "fallback",
                "containers",
                "process_ids",
                "planned",
                "plan",
                "error_code",
                "stage",
                "resumable",
            ].includes(key)
        ),
    );
    return { stdout: JSON.stringify(safe), healthy: result.healthy === true };
}
async function publish(ctx: Context, name: string, value: unknown) {
    await ctx.writeResource(name, name, value);
    return value;
}
export const behavior = {
    type: "@nckrtl/fleet-docker-assets",
    version: "2026.11.01.1",
    resources: {
        terminal: {
            schema: z.object({ stdout: z.string(), healthy: z.boolean() }),
            lifetime: "infinite",
            garbageCollection: 10,
        },
        assets: { schema: Assets, lifetime: "infinite", garbageCollection: 10 },
        decoded: {
            schema: ObjectInput,
            lifetime: "infinite",
            garbageCollection: 10,
        },
    },
    methods: {
        read: {
            description:
                "Validate public Docker pins and resolve shipped actions",
            arguments: Args,
            execute: (raw: z.infer<typeof Args>, ctx: Context) => {
                const input = Args.parse(raw);
                if (
                    input.mode === "restore-policies" &&
                    input.migration.fallback
                ) throw new Error("Policy recovery cannot select fallback");
                const file = ctx.extensionFile("scripts/actions.py");
                const value = {
                    scripts: file.slice(0, file.lastIndexOf("/")),
                    input_json: JSON.stringify(input),
                };
                return publish(ctx, "assets", value);
            },
        },
        decode: {
            description: "Decode a public inventory or readiness result",
            arguments: Stdout,
            execute: (args: z.infer<typeof Stdout>, ctx: Context) =>
                publish(
                    ctx,
                    "decoded",
                    ObjectInput.parse(JSON.parse(args.stdout)),
                ),
        },
        result: {
            description:
                "Assemble the bounded terminal report without host work",
            arguments: z.object({ steps: ObjectInput, mode: z.string() }),
            execute: (
                args: { steps: Record<string, unknown>; mode: string },
                ctx: Context,
            ) => publish(ctx, "terminal", terminal(args.steps, args.mode)),
        },
    },
};
