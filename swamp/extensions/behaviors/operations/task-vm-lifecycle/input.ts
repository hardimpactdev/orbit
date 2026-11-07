import { z } from "npm:zod@4.4.3";

const Id = z.number().int().positive();
const Name = z.string().regex(/^[A-Za-z0-9][A-Za-z0-9-]{0,62}$/);
export const Ssh = z.object({
    control_node_id: z.number().int().positive(),
    identity_file: z.string().startsWith("/"),
    public_key: z.string().min(1),
    target: z.object({
        name: z.string().min(1),
        node_id: Id.optional(),
        host: z.string().min(1),
        user: z.string().min(1),
        port: z.number().int().min(1).max(65535),
        host_keys: z.array(z.string().min(1)).min(1),
    }).strict(),
}).strict();
export const ProviderInput = z.object({
    task_vm_id: Id,
    provider: z.literal("incus"),
    name: Name,
    vm: z.object({
        project: Name,
        name: Name,
        pool: z.string().regex(/^[A-Za-z0-9][A-Za-z0-9_-]{0,62}$/).optional(),
    }).strict(),
    host_ssh_execution: Ssh,
}).strict().superRefine((v, ctx) => {
    if (
        v.name !== `tvm-${v.task_vm_id}` ||
        v.vm.name !== v.name
    ) {
        ctx.addIssue({
            code: "custom",
            message: "task_vm.provider_identity_invalid",
        });
    }
});
export const Action = z.enum(["start", "stop", "delete", "inspect"]);
export type ProviderIntent = z.infer<typeof ProviderInput>;
export type PowerAction = z.infer<typeof Action>;
export const ProviderReceipt = z.object({
    task_vm_id: Id,
    provider: z.literal("incus"),
    action: Action,
    running: z.boolean(),
    absent: z.boolean(),
    settled: z.literal(true),
}).strict();

/** Decode one observation; the native provider phase owns any bounded state wait. */
export function observation(
    intent: ProviderIntent,
    action: PowerAction,
    stdout: string,
) {
    let state: "started" | "stopped" | "absent" | "pending";
    const disk: string | null = null;
    const rows = z.array(z.object({ name: z.string() }).passthrough())
        .parse(JSON.parse(stdout))
        .filter((v) => v.name === intent.name);
    if (rows.length > 1) {
        throw new Error("task_vm.provider_identity_invalid");
    }
    if (!rows.length) state = "absent";
    else {
        const row = z.object({
            type: z.literal("virtual-machine"),
            status: z.string(),
        }).passthrough().parse(rows[0]);
        state = row.status === "Running"
            ? "started"
            : row.status === "Stopped"
            ? "stopped"
            : "pending";
    }
    if (state === "absent" && action !== "delete" && action !== "inspect") {
        throw new Error("task_vm.vm_missing");
    }
    if (action === "delete" && state === "started") {
        throw new Error("task_vm.not_settled");
    }
    if (state === "pending") throw new Error("task_vm.not_settled");
    return {
        state,
        disk,
        change: action === "inspect"
            ? false
            : action === "start"
            ? state !== "started"
            : action === "stop"
            ? state !== "stopped"
            : state !== "absent",
    };
}
export function receipt(
    intent: ProviderIntent,
    action: PowerAction,
    stdout: string,
) {
    const observed = observation(intent, action, stdout);
    if (observed.change) throw new Error("task_vm.not_settled");
    return ProviderReceipt.parse({
        task_vm_id: intent.task_vm_id,
        provider: intent.provider,
        action,
        running: observed.state === "started",
        absent: observed.state === "absent",
        settled: true,
    });
}
