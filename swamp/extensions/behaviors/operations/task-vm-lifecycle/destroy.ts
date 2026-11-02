import { z } from "npm:zod@4.4.3";
import { ProviderInput, ProviderReceipt } from "./input.ts";
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
export function seedPresent(raw: unknown, stdout: string): boolean {
    const intent = Intent.parse(raw);
    if (intent.provider === "upcloud") return false;
    const rows = z.array(
        z.object({ name: z.string(), type: z.string() }).passthrough(),
    ).parse(JSON.parse(stdout));
    return rows.some((row) =>
        row.type === "custom" &&
        row.name === intent.provider_input.name + "-topology-seed"
    );
}
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
    removalComplete: boolean,
    seedStdout: string,
) {
    const intent = Intent.parse(raw);
    const provider = ProviderReceipt.parse(providerRaw);
    if (seedPresent(raw, seedStdout)) {
        throw new Error("task_vm.destroy_incomplete");
    }
    if (
        provider.task_vm_id !== intent.task_vm_id ||
        provider.provider !== intent.provider ||
        provider.action !== "delete" || !provider.absent || provider.running
    ) throw new Error("task_vm.destroy_incomplete");
    if (intent.guest_node_id !== null && !removalComplete) {
        throw new Error("task_vm.destroy_incomplete");
    }
    const node = intent.removal;
    const removal = intent.guest_node_id === null ? null : {
        schema_version: 1,
        node_id: intent.guest_node_id,
        name: node?.name,
        removed: true,
        offline: true,
        roles_shed: z.array(z.object({ role: z.string() })).parse(node?.roles)
            .map((role) => role.role),
        retained_on_node: [
            ...z.array(z.string()).parse(node?.preserved_on_node),
            ...z.array(z.string()).parse(node?.retained_on_node),
        ],
        follow_up:
            "Discard the Node or clear the reported retained artifacts when it is reachable.",
    };
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
    version: "2026.11.01.2",
    resources: {
        plan: {
            schema: z.object({
                provider: z.enum(["incus", "upcloud"]),
                model_required: z.boolean(),
                node_present: z.boolean(),
                seed_observe_argv: z.array(z.string()),
                seed_delete_argv: z.array(z.string()),
            }),
            lifetime: "infinite",
            garbageCollection: 10,
        },
        seed: {
            schema: z.object({ present: z.boolean() }),
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
        seed: {
            description:
                "Decode the exact same-VM custom seed volume observation",
            arguments: z.object({ intent: Intent, stdout: z.string() }),
            async execute(
                args: { intent: unknown; stdout: string },
                ctx: Context,
            ) {
                return {
                    dataHandles: [
                        await ctx.writeResource("seed", "seed", {
                            present: seedPresent(args.intent, args.stdout),
                        }),
                    ],
                };
            },
        },
        plan: {
            description: "Read the admitted provider destruction scope",
            arguments: z.object({ intent: Intent }),
            async execute(
                args: { intent: z.infer<typeof Intent> },
                ctx: Context,
            ) {
                return {
                    dataHandles: [
                        await ctx.writeResource("plan", "plan", {
                            provider: args.intent.provider,
                            model_required: args.intent.model_key_required,
                            node_present: args.intent.guest_node_id !== null,
                            seed_observe_argv: args.intent.provider === "incus"
                                ? [
                                    "/usr/bin/sudo",
                                    "-n",
                                    "--",
                                    "/usr/bin/incus",
                                    "--project",
                                    args.intent.provider_input.vm!.project,
                                    "storage",
                                    "volume",
                                    "list",
                                    args.intent.provider_input.vm!.pool!,
                                    "--format",
                                    "json",
                                ]
                                : [],
                            seed_delete_argv: args.intent.provider === "incus"
                                ? [
                                    "/usr/bin/sudo",
                                    "-n",
                                    "--",
                                    "/usr/bin/incus",
                                    "--project",
                                    args.intent.provider_input.vm!.project,
                                    "storage",
                                    "volume",
                                    "delete",
                                    args.intent.provider_input.vm!.pool!,
                                    args.intent.provider_input.name +
                                    "-topology-seed",
                                ]
                                : [],
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
                removal_complete: z.boolean(),
                seed_stdout: z.string(),
            }),
            async execute(
                args: {
                    intent: unknown;
                    provider: unknown;
                    removal_complete: boolean;
                    seed_stdout: string;
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
                                args.removal_complete,
                                args.seed_stdout,
                            ),
                        ),
                    ],
                };
            },
        },
    },
};
