import { z } from "npm:zod@4.4.3";

const Sha = z.string().regex(/^[a-f0-9]{64}$/);
const Identity = z.object({
    task_vm_id: z.number().int().positive(),
    guest_node_id: z.number().int().positive(),
    user: z.literal("orbit"),
    runtime: z.object({ pi_sha256: Sha }),
});
const Args = z.object({ intent: Identity });
const Assets = z.object({
    task_vm_id: z.number().int().positive(),
    sha256: Sha,
    inspect_script: z.string(),
    install_script: z.string(),
    verify_script: z.string(),
});
const Inspection = z.object({
    task_vm_id: z.number().int().positive(),
    sha256: Sha,
    current: z.boolean(),
}).strict();
const ReadInspection = z.object({
    task_vm_id: z.number().int().positive(),
    sha256: Sha,
    stdout: z.string().max(1024),
});
type Context = {
    extensionFile: (name: string) => string;
    writeResource: (
        spec: string,
        name: string,
        value: unknown,
    ) => Promise<unknown>;
};

/** Public schemas and native asset paths only; every filesystem action is a script step. */
export const behavior = {
    type: "@nckrtl/task-vm-pi-assets",
    version: "2026.10.31.1",
    resources: {
        assets: {
            description: "Admitted public Pi script inputs",
            schema: Assets,
            lifetime: "infinite",
            garbageCollection: 10,
        },
        inspection: {
            description: "Public installed Pi observation",
            schema: Inspection,
            lifetime: "infinite",
            garbageCollection: 10,
        },
    },
    methods: {
        read: {
            description:
                "Resolve native script assets and the public admitted Pi digest",
            arguments: Args,
            async execute(args: z.infer<typeof Args>, ctx: Context) {
                const intent = Identity.parse(args.intent);
                const result = Assets.parse({
                    task_vm_id: intent.task_vm_id,
                    sha256: intent.runtime.pi_sha256,
                    inspect_script: ctx.extensionFile("scripts/pi_inspect.py"),
                    install_script: ctx.extensionFile("scripts/pi_install.py"),
                    verify_script: ctx.extensionFile("scripts/pi_verify.py"),
                });
                await ctx.writeResource("assets", "assets", result);
                return result;
            },
        },
        inspection: {
            description:
                "Shape one command's public observation for the native install guard",
            arguments: ReadInspection,
            async execute(args: z.infer<typeof ReadInspection>, ctx: Context) {
                let result: z.infer<typeof Inspection>;
                try {
                    result = Inspection.parse(JSON.parse(args.stdout));
                    if (
                        result.task_vm_id !== args.task_vm_id ||
                        result.sha256 !== args.sha256
                    ) {
                        throw new Error();
                    }
                } catch {
                    throw new Error("task_vm.pi_artifact_failed");
                }
                await ctx.writeResource("inspection", "inspection", result);
                return result;
            },
        },
    },
};
