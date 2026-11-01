import { z } from "npm:zod@4.4.3";
import { ProviderInput, ProviderReceipt } from "./input.ts";
const Id = z.number().int().positive();
const Intent = z.object({
    task_vm_id: Id,
    guest_node_id: Id.nullable(),
    provider: z.literal("incus"),
    provider_input: ProviderInput,
    model_key_required: z.boolean(),
    removal: z.record(z.string(), z.unknown()).nullable(),
}).passthrough().superRefine((v, ctx) => {
    if (
        v.task_vm_id !== v.provider_input.task_vm_id ||
        v.provider_input.provider !== "incus" ||
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
export function destroyTerminal(
    raw: unknown,
    providerRaw: unknown,
    removalStdout: string,
) {
    const intent = Intent.parse(raw);
    const provider = ProviderReceipt.parse(providerRaw);
    if (
        provider.task_vm_id !== intent.task_vm_id ||
        provider.provider !== "incus" ||
        provider.action !== "delete" || !provider.absent || provider.running
    ) throw new Error("task_vm.destroy_incomplete");
    const removal = intent.guest_node_id === null ? null : z.object({
        node_id: Id,
        removed: z.literal(true),
        offline: z.literal(true),
    }).passthrough().parse(JSON.parse(removalStdout));
    if (removal !== null && removal.node_id !== intent.guest_node_id) {
        throw new Error("task_vm.destroy_incomplete");
    }
    return {
        result_json: JSON.stringify({
            task_vm_id: intent.task_vm_id,
            guest_node_id: intent.guest_node_id,
            destroyed: true,
            cleanup_complete: true,
            provider,
            removal,
        }),
    };
}
export const behavior = {
    type: "@nckrtl/task-vm-destroy-input",
    version: "2026.11.01.1",
    resources: {
        plan: {
            schema: z.object({
                model_required: z.boolean(),
                node_present: z.boolean(),
            }),
            lifetime: "infinite",
            garbageCollection: 10,
        },
        revocation: {
            schema: z.object({ task_vm_id: Id, revoked: z.boolean() }),
            lifetime: "infinite",
            garbageCollection: 10,
        },
        terminal: {
            schema: z.object({ result_json: z.string() }),
            lifetime: "infinite",
            garbageCollection: 10,
        },
    },
    methods: {
        plan: {
            description: "Read the admitted Incus destruction scope",
            arguments: z.object({ intent: Intent }),
            async execute(
                args: { intent: z.infer<typeof Intent> },
                ctx: Context,
            ) {
                return {
                    dataHandles: [
                        await ctx.writeResource("plan", "plan", {
                            model_required: args.intent.model_key_required,
                            node_present: args.intent.guest_node_id !== null,
                        }),
                    ],
                };
            },
        },
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
        terminal: {
            description:
                "Fold complete provider and canonical Node cleanup into public JSON",
            arguments: z.object({
                intent: Intent,
                provider: ProviderReceipt,
                removal_stdout: z.string(),
            }),
            async execute(
                args: {
                    intent: unknown;
                    provider: unknown;
                    removal_stdout: string;
                },
                ctx: Context,
            ) {
                return {
                    dataHandles: [
                        await ctx.writeResource(
                            "terminal",
                            "terminal",
                            destroyTerminal(
                                args.intent,
                                args.provider,
                                args.removal_stdout,
                            ),
                        ),
                    ],
                };
            },
        },
    },
};
