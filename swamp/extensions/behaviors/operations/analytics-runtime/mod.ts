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
export function plan(raw: unknown, directory: string) {
    const i = Input.parse(raw);
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
        directory,
        control,
        before: intent(i.before),
        after: intent(i.after),
        definition_json: JSON.stringify(i.after),
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
    version: "2026.11.01.1",
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
                "Validate captured public runtime and resolve initialization asset",
            arguments: z.object({ intent: Input }),
            execute: async (
                args: { intent: z.infer<typeof Input> },
                ctx: {
                    extensionFile(n: string): string;
                    writeResource(
                        s: string,
                        n: string,
                        v: unknown,
                    ): Promise<unknown>;
                },
            ) => {
                const v = plan(
                    args.intent,
                    ctx.extensionFile("scripts/initialize.py").replace(
                        /\/[^/]+$/,
                        "",
                    ),
                );
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
