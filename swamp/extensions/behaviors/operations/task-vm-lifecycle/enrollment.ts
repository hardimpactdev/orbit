import { Guest, guestConnection } from "./ssh_input.ts";
import { z } from "npm:zod@4.4.3";
import { Ssh } from "./input.ts";
const Id = z.number().int().positive();
const Key = z.string().regex(/^[A-Za-z0-9+/]{43}=$/);
const Intent = z.object({
    task_vm_id: Id,
    guest_node_id: Id,
    user: z.literal("orbit"),
    provider: z.enum(["incus", "upcloud"]),
    node: z.object({ node_id: Id, wireguard_ip: z.ipv4() }),
    ssh_identity: Ssh.omit({ target: true }),
    runtime: z.object({ process: z.object({ process_id: Id }) }),
}).passthrough();
const Provider = z.object({
    provider_id: z.uuid().nullable(),
    address: z.ipv4(),
    image_fingerprint: z.string(),
    running: z.literal(true),
    settled: z.literal(true),
}).passthrough();
const Terminal = z.object({
    result_json: z.string(),
    task_vm_id: Id,
    guest_node_id: Id,
    ready: z.literal(true),
    provider: Provider,
    enrollment: z.record(z.string(), z.unknown()),
});
const TerminalArgs = z.object({
    intent: Intent,
    provider: Provider,
    guest: Guest,
    peer: z.object({ public_key: Key }),
    model_stdout: z.string(),
    runtime_stdout: z.string(),
    observer_stdout: z.string(),
});
export function enrollmentTerminal(raw: unknown) {
    const {
        intent,
        provider,
        guest,
        peer,
        model_stdout,
        runtime_stdout,
        observer_stdout,
    } = TerminalArgs.parse(raw);
    const model = z.object({ task_vm_id: Id, issued: z.literal(true) }).parse(
        JSON.parse(model_stdout),
    );
    const runtime = z.object({
        task_vm_id: Id,
        guest_node_id: Id,
        process_id: Id,
        ready: z.literal(true),
    }).parse(JSON.parse(runtime_stdout));
    const observer = z.object({
        observer_ready: z.literal(true),
        observer_digest: z.string().regex(/^[a-f0-9]{64}$/),
    }).parse(JSON.parse(observer_stdout));
    if (
        model.task_vm_id !== intent.task_vm_id ||
        runtime.task_vm_id !== intent.task_vm_id ||
        runtime.guest_node_id !== intent.guest_node_id ||
        runtime.process_id !== intent.runtime.process.process_id
    ) throw new Error("task_vm.enrollment_invalid");
    return {
        result_json: JSON.stringify({
            task_vm_id: intent.task_vm_id,
            guest_node_id: intent.guest_node_id,
            ready: true,
            provider,
            enrollment: {
                ready: true,
                node: {
                    ...guest.pin,
                    wireguard_public_key: peer.public_key,
                    architecture: "x86_64",
                    address: provider.address,
                    observer_digest: observer.observer_digest,
                },
                process: { process_id: runtime.process_id, status: "active" },
            },
        }),
    };
}
type Context = {
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};
export const behavior = {
    type: "@nckrtl/task-vm-enrollment-input",
    version: "2026.11.01.2",
    resources: {
        plan: {
            schema: z.object({ provider: z.enum(["incus", "upcloud"]) }),
            lifetime: "infinite",
            garbageCollection: 10,
        },
        guest: { schema: Guest, lifetime: "infinite", garbageCollection: 10 },
        terminal: {
            schema: Terminal,
            lifetime: "infinite",
            garbageCollection: 10,
        },
    },
    methods: {
        forward: {
            description:
                "Expose the child's complete public receipt to its owning parent",
            arguments: z.object({ receipt: Terminal }),
            async execute(
                args: { receipt: z.infer<typeof Terminal> },
                ctx: Context,
            ) {
                return {
                    dataHandles: [
                        await ctx.writeResource(
                            "terminal",
                            "terminal",
                            args.receipt,
                        ),
                    ],
                };
            },
        },
        plan: {
            description:
                "Expose the captured provider for native branch guards",
            arguments: z.object({ provider: z.enum(["incus", "upcloud"]) }),
            async execute(
                args: { provider: "incus" | "upcloud" },
                ctx: Context,
            ) {
                return {
                    dataHandles: [
                        await ctx.writeResource("plan", "plan", args),
                    ],
                };
            },
        },
        guest: {
            description: "Project admitted public provider and SSH identity",
            arguments: z.object({
                intent: Intent,
                provider: Provider,
                public_key: z.string(),
            }),
            async execute(
                args: {
                    intent: unknown;
                    provider: unknown;
                    public_key: string;
                },
                ctx: Context,
            ) {
                return {
                    dataHandles: [
                        await ctx.writeResource(
                            "guest",
                            "guest",
                            await guestConnection(
                                args.intent,
                                args.provider,
                                args.public_key,
                            ),
                        ),
                    ],
                };
            },
        },
        terminal: {
            description:
                "Fold complete same-VM native receipts into public terminal JSON",
            arguments: TerminalArgs,
            async execute(args: z.infer<typeof TerminalArgs>, ctx: Context) {
                return {
                    dataHandles: [
                        await ctx.writeResource(
                            "terminal",
                            "terminal",
                            (() => {
                                const value = enrollmentTerminal(args);
                                return {
                                    ...JSON.parse(value.result_json),
                                    ...value,
                                };
                            })(),
                        ),
                    ],
                };
            },
        },
    },
};
