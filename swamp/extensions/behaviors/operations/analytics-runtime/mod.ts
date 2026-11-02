import { z } from "npm:zod@4.4.3";
import { Container } from "../processes-schedules/container-input.ts";
const Digest = z.string().regex(/^[a-f0-9]{64}$/);
const Version = z.string().regex(/^\d+\.\d+\.\d+$/);
const Definition = Container.omit({ environment: true }).extend({
    process_id: z.number().int().positive(),
    container: z.string(),
    desired: z.literal("running"),
    spec: Digest,
});
export const Input = z.object({
    version: Version,
    previous_version: Version,
    process_id: z.number().int().positive(),
    assignment_id: z.number().int().positive(),
    before: Definition,
    after: Definition,
    health_url: z.string().url(),
    ssh_execution: z.record(z.string(), z.unknown()),
}).strict();
const RunId = z.string().regex(/^[a-f0-9-]{36}$/);
// One `docker run --rm` of the new image per initialization phase; the private env arrives on stdin.
const initialize = (
    d: z.infer<typeof Definition>,
    runId: string,
    phase: "createdb" | "migrate",
) => [
    "/usr/bin/docker",
    "--host=unix:///var/run/docker.sock",
    "run",
    "--rm",
    "--name",
    `orbit-analytics-${runId}-${phase}`,
    "--env-file",
    "/dev/stdin",
    ...d.mounts.flatMap((m) => [
        "--volume",
        `${m.source}:${m.target}${m.read_only ? ":ro" : ""}`,
    ]),
    d.image,
    "/entrypoint.sh",
    "db",
    phase,
];
export function plan(raw: unknown, rawRunId: string) {
    const i = Input.parse(raw), runId = RunId.parse(rawRunId);
    if (
        i.before.process_id !== i.process_id ||
        i.after.process_id !== i.process_id ||
        i.before.container !== `orbit-process-${i.process_id}-plausible` ||
        i.after.container !== i.before.container ||
        i.after.image !== `ghcr.io/plausible/community-edition:v${i.version}`
    ) throw new Error("analytics.runtime_invalid");
    const control = {
        process_id: i.process_id,
        runtime: "docker" as const,
        name: i.before.container,
        on_demand: false,
        vite: null,
    };
    const intent = (d: z.infer<typeof Definition>) => ({
        control,
        ssh_execution: i.ssh_execution,
        installation: {
            process_id: control.process_id,
            runtime: control.runtime,
            name: control.name,
            on_demand: false,
            desired: "running",
            spec: d.spec,
            retired: null,
            has_environment: true,
            environment_path:
                `/etc/orbit/processes/${i.process_id}/${d.spec}.env`,
            definition: {
                image: d.image,
                command: d.command,
                restart: d.restart,
                working_directory: d.working_directory,
                ports: d.ports,
                mounts: d.mounts,
            },
        },
    });
    return {
        control,
        before: intent(i.before),
        after: intent(i.after),
        createdb_argv: initialize(i.after, runId, "createdb"),
        migrate_argv: initialize(i.after, runId, "migrate"),
    };
}
export function observe(raw: unknown, stdout: string) {
    const i = Input.parse(raw),
        [managed, kind, id, spec, state] = stdout.trimEnd().split("\n");
    if (
        managed !== "true" || kind !== "process" ||
        id !== String(i.process_id) ||
        ![i.before.spec, i.after.spec].includes(spec) || state !== "running"
    ) throw new Error("analytics.runtime_changed");
    return { replace: spec !== i.after.spec };
}
export const behavior = {
    type: "@nckrtl/analytics-runtime",
    version: "2026.11.02.1",
    resources: {
        metadata: {
            schema: z.record(z.string(), z.unknown()),
            lifetime: "infinite",
            garbageCollection: 10,
        },
    },
    methods: {
        plan: {
            description:
                "Validate captured public runtime and render the initialization commands",
            arguments: z.object({ intent: Input, run_id: RunId }),
            execute: async (
                args: { intent: z.infer<typeof Input>; run_id: string },
                ctx: {
                    writeResource(
                        s: string,
                        n: string,
                        v: unknown,
                    ): Promise<unknown>;
                },
            ) => {
                const v = plan(args.intent, args.run_id);
                await ctx.writeResource("metadata", "metadata", v);
                return v;
            },
        },
        observe: {
            description: "Check the public owned container observation",
            arguments: z.object({ intent: Input, stdout: z.string() }),
            execute: async (
                args: { intent: z.infer<typeof Input>; stdout: string },
                ctx: {
                    writeResource(
                        s: string,
                        n: string,
                        v: unknown,
                    ): Promise<unknown>;
                },
            ) => {
                const v = observe(args.intent, args.stdout);
                await ctx.writeResource("metadata", "metadata", v);
                return v;
            },
        },
    },
};
