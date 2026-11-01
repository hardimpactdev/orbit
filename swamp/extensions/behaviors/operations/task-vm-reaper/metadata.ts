import { z } from "npm:zod@4.4.3";
import { Ssh } from "../task-vm-lifecycle/input.ts";

export const LastRunMarker = "/var/lib/orbit/swamp-proof/last-run";
const Vm = z.object({
    task_vm_id: z.number().int().positive(),
    group_id: z.number().int().positive().nullable(),
    group_title: z.string().nullable(),
    ssh: Ssh.nullable(),
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
    steps: z.record(z.string(), z.unknown()).default({}),
}).strict();
type Inputs = z.infer<typeof Input>;
type VmFacts = z.infer<typeof Vm>;
const proofGroup = (vm: VmFacts, input: Inputs) =>
    input.proof_project_id !== null && vm.project_id === input.proof_project_id;
const topologyGroup = (vm: VmFacts, input: Inputs) =>
    proofGroup(vm, input) && vm.group_title?.startsWith("topology ") === true;
const readTargets = (input: Inputs) =>
    input.vms.filter((vm) =>
        vm.claimed_at !== null && topologyGroup(vm, input) && vm.ssh !== null
    );

/** Read failure is unknown. Only an actual successful stat or ENOENT proves activity. */
function lastRunEpoch(step: unknown): number | null | undefined {
    const parsed = z.object({
        status: z.string(),
        outputs: z.unknown().optional(),
    }).safeParse(step);
    if (!parsed.success || parsed.data.status !== "succeeded") return undefined;
    const output = z.object({
        exitCode: z.number().nullable(),
        signal: z.string().nullable().optional(),
        stdout: z.string().optional(),
        stderr: z.string().optional(),
    }).safeParse(parsed.data.outputs);
    if (!output.success || output.data.signal) return undefined;
    const value = output.data;
    if (value.exitCode === 0 && /^\d+\s*$/.test(value.stdout ?? "")) {
        const epoch = Number(value.stdout!.trim());
        return Number.isSafeInteger(epoch) ? epoch : undefined;
    }
    if (
        value.exitCode === 1 && value.stderr?.includes(LastRunMarker) &&
        value.stderr.includes("No such file or directory")
    ) return null;
    return undefined;
}

/** The native scheduler owns age policy and the native-only last-run observation. */
export function selectStuckVms(raw: unknown, now = new Date()) {
    const input = Input.parse(raw);
    const targets = readTargets(input);
    const requests = input.vms.flatMap((vm) => {
        if (vm.claimed_at === null) return [];
        let reason: string | null = null;
        const age = vm.group_created_at === null
            ? null
            : now.getTime() - Date.parse(vm.group_created_at);
        if (topologyGroup(vm, input)) {
            if (age !== null && age >= 8 * 60 * 60_000) {
                reason = "topology_max_age";
            } else if (vm.group_created_at !== null) {
                const index = targets.findIndex((target) =>
                    target.task_vm_id === vm.task_vm_id
                );
                const activity = index < 0
                    ? undefined
                    : lastRunEpoch(input.steps[`inspect-${index}`]);
                if (activity !== undefined) {
                    const last = Math.max(
                        Date.parse(vm.group_created_at),
                        activity === null ? 0 : activity * 1000,
                    );
                    if (now.getTime() - last >= 60 * 60_000) {
                        reason = "topology_idle";
                    }
                }
            }
        } else if (proofGroup(vm, input)) {
            if (
                vm.group_title?.startsWith("proof ") && age !== null &&
                age >= 45 * 60_000
            ) reason = "proof_age";
        } else if (!vm.group_live) {
            reason = "no_live_group";
        } else if (
            vm.assistance_requested_at !== null &&
            now.getTime() - Date.parse(vm.assistance_requested_at) >= 7_200_000
        ) {
            reason = "assistance_unanswered";
        } else if (now.getTime() - Date.parse(vm.progress_at) >= 3_600_000) {
            reason = "no_progress";
        }
        return reason === null ? [] : [{ ...vm, reason }];
    });
    const result = {
        ready: true,
        expectations_hash: input.expectations_hash,
        requests,
    };
    return { ...result, stdout: JSON.stringify(result) };
}

type Context = {
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};
export const behavior = {
    type: "@nckrtl/task-vm-reaper-input",
    version: "2026.11.01.2",
    resources: {
        plan: {
            schema: z.record(z.string(), z.unknown()),
            lifetime: "1d",
            garbageCollection: 10,
        },
        result: {
            schema: z.record(z.string(), z.unknown()),
            lifetime: "1d",
            garbageCollection: 10,
        },
    },
    methods: {
        plan: {
            description:
                "Select pinned proof VM hosts for one native last-run stat each",
            arguments: Input,
            execute: async (args: Inputs, ctx: Context) => {
                const result = { targets: readTargets(Input.parse(args)) };
                await ctx.writeResource("plan", "plan", result);
                return result;
            },
        },
        select: {
            description:
                "Select expired VMs from captured facts and native stat results",
            arguments: Input,
            execute: async (args: Inputs, ctx: Context) => {
                const result = selectStuckVms(args);
                await ctx.writeResource("result", "result", result);
                return result;
            },
        },
    },
};
