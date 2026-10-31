import { z } from "npm:zod@4.4.3";

const Result = z.union([
    z.object({
        task_vm_id: z.number().int().positive(),
        issued: z.literal(true),
    }).strict(),
    z.object({
        task_vm_id: z.number().int().positive(),
        revoked: z.literal(true),
    }).strict(),
]);
const Args = z.object({
    task_vm_id: z.number().int().positive(),
    stdout: z.string().max(1024),
});

/** Schema-only public output shaping; operational actions live in native shell steps. */
export const behavior = {
    type: "@nckrtl/task-vm-model-result",
    version: "2026.10.31.1",
    resources: {
        result: { description: "Public VM model-key outcome", schema: Result },
    },
    methods: {
        read: {
            description: "Shape a command's public JSON result for the parent",
            arguments: Args,
            async execute(args: z.infer<typeof Args>, ctx: {
                writeResource: (
                    spec: string,
                    name: string,
                    value: unknown,
                ) => Promise<unknown>;
            }) {
                let result: z.infer<typeof Result>;
                try {
                    result = Result.parse(JSON.parse(args.stdout));
                    if (result.task_vm_id !== args.task_vm_id) {
                        throw new Error();
                    }
                } catch {
                    throw new Error("task_vm.model_key_failed");
                }
                const handle = await ctx.writeResource(
                    "result",
                    "model-key-result",
                    result,
                );
                return { dataHandles: [handle] };
            },
        },
    },
};
