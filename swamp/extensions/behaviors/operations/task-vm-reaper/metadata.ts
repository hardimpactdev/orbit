import { z } from "npm:zod@4.4.3";

const Vm = z.object({
    task_vm_id: z.number().int().positive(),
    group_id: z.number().int().positive().nullable(),
    provider_id: z.string().nullable(),
    name: z.string().min(1),
    node_id: z.number().int().positive().nullable(),
    host_node_id: z.number().int().positive().nullable(),
    claimed_at: z.iso.datetime().nullable(),
    group_live: z.boolean(),
    progress_at: z.iso.datetime(),
    assistance_requested_at: z.iso.datetime().nullable(),
    project_id: z.number().int().positive().nullable(),
    group_created_at: z.iso.datetime().nullable(),
}).strict();
const Input = z.object({
    expectations_hash: z.string().regex(/^[a-f0-9]{64}$/),
    proof_project_id: z.number().int().positive().nullable(),
    vms: z.array(Vm),
}).strict();

/** The native scheduler owns time. The Gateway publishes recorded facts only. */
export function selectStuckVms(raw: unknown, now = new Date()) {
    const input = Input.parse(raw);
    const requests = input.vms.flatMap((vm) => {
        if (vm.claimed_at === null) return [];
        const proof = input.proof_project_id !== null &&
            vm.project_id === input.proof_project_id;
        const reason = proof
            ? vm.group_created_at !== null &&
                    now.getTime() - Date.parse(vm.group_created_at) >=
                        45 * 60_000
                ? "proof_age"
                : null
            : !vm.group_live
            ? "no_live_group"
            : vm.assistance_requested_at !== null &&
                    now.getTime() -
                                Date.parse(vm.assistance_requested_at) >=
                        7_200_000
            ? "assistance_unanswered"
            : now.getTime() - Date.parse(vm.progress_at) >= 3_600_000
            ? "no_progress"
            : null;
        return reason === null ? [] : [{ ...vm, reason }];
    });
    const result = {
        ready: true,
        expectations_hash: input.expectations_hash,
        requests,
    };
    return { ...result, stdout: JSON.stringify(result) };
}

export const behavior = {
    type: "@nckrtl/task-vm-reaper-input",
    version: "2026.11.01.1",
    resources: {
        result: {
            schema: z.record(z.string(), z.unknown()),
            lifetime: "1d",
            garbageCollection: 10,
        },
    },
    methods: {
        select: {
            description: "Select only VMs whose published deadline has passed",
            arguments: Input,
            execute: async (
                args: z.infer<typeof Input>,
                ctx: {
                    writeResource(
                        spec: string,
                        name: string,
                        value: unknown,
                    ): Promise<unknown>;
                },
            ) => {
                const result = selectStuckVms(args);
                await ctx.writeResource("result", "result", result);
                return result;
            },
        },
    },
};
