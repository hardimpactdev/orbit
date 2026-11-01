import { z } from "npm:zod@4.4.3";

const Id = z.number().int().positive();
export const WorkspaceInput = z.object({
    task_vm_id: Id,
    group_id: Id.optional(),
    instance_id: Id.optional(),
    project_id: Id.optional(),
    checkout_path: z.string().min(2).max(4096).startsWith("/")
        .refine((value) => !value.includes("\0")),
    ssh_execution: z.object({
        control_node_id: z.literal(209),
        identity_file: z.string().startsWith("/"),
        public_key: z.string().min(1),
        target: z.object({
            name: z.string().min(1),
            host: z.string().min(1),
            user: z.string().min(1),
            port: z.number().int().min(1).max(65535),
            host_keys: z.array(z.string().min(1)).min(1),
        }).strict(),
    }).strict(),
}).strict();
export const WorkspaceResult = WorkspaceInput.pick({
    task_vm_id: true,
    group_id: true,
    instance_id: true,
    project_id: true,
    checkout_path: true,
}).extend({ ready: z.literal(true) }).strict();
export function workspaceResult(raw: unknown): z.infer<typeof WorkspaceResult> {
    const { stdout } = z.object({ stdout: z.string() }).passthrough().parse(
        raw,
    );
    return WorkspaceResult.parse(JSON.parse(stdout));
}
