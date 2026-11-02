import { z } from "npm:zod@4.4.3";
import { ProviderInput } from "./input.ts";
const Id = z.number().int().positive();
const Intent = z.object({
    task_vm_id: Id,
    guest_node_id: Id.nullable(),
    provider: z.enum(["incus", "upcloud"]),
    provider_input: ProviderInput,
    model_key_required: z.boolean(),
    removal: z.record(z.string(), z.unknown()).nullable(),
}).passthrough().superRefine((v, ctx) => {
    if (
        (v.provider === "incus" && !v.provider_input.vm?.pool) ||
        v.task_vm_id !== v.provider_input.task_vm_id ||
        v.provider_input.provider !== v.provider ||
        (v.guest_node_id !== null &&
            (v.removal?.node_id !== v.guest_node_id ||
                v.removal?.machine_deleted !== true))
    ) {
        ctx.addIssue({
            code: "custom",
            message: "task_vm.destroy_scope_invalid",
        });
    }
});
const Revocation = z.object({ task_vm_id: Id, revoked: z.literal(true) })
    .passthrough();
type Context = {
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};
export function destroyRevocation(raw: unknown, stdout: string) {
    const intent = Intent.parse(raw);
    if (
        intent.model_key_required &&
        Revocation.parse(JSON.parse(stdout)).task_vm_id !== intent.task_vm_id
    ) {
        throw new Error("task_vm.model_key_failed");
    }
    return {
        task_vm_id: intent.task_vm_id,
        revoked: intent.model_key_required,
    };
}
export const behavior = {
    type: "@nckrtl/task-vm-destroy-input",
    version: "2026.11.02.1",
    resources: {
        revocation: {
            schema: z.object({ task_vm_id: Id, revoked: z.boolean() }),
            lifetime: "infinite",
            garbageCollection: 10,
        },
    },
    methods: {
        revocation: {
            description:
                "Require the same VM model revocation before provider deletion",
            arguments: z.object({ intent: Intent, stdout: z.string() }),
            async execute(
                args: { intent: unknown; stdout: string },
                ctx: Context,
            ) {
                return {
                    dataHandles: [
                        await ctx.writeResource(
                            "revocation",
                            "revocation",
                            destroyRevocation(args.intent, args.stdout),
                        ),
                    ],
                };
            },
        },
    },
};
