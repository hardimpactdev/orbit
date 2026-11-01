import { z } from "npm:zod@4.4.3";
import { Ssh } from "./input.ts";
const Id = z.number().int().positive();
const Intent = z.object({
    guest_node_id: Id,
    node: z.object({ node_id: Id, wireguard_ip: z.ipv4() }),
    ssh_identity: Ssh.omit({ target: true }),
});
const Pin = z.object({
    ssh_host_key_type: z.literal("ssh-ed25519"),
    ssh_host_key: z.string(),
    ssh_host_fingerprint: z.string(),
    host_keys: z.array(z.string()).length(1),
});
export const Guest = z.object({
    pin: Pin,
    ssh_execution: Ssh,
    public_ssh_execution: Ssh,
});

/** The Incus host or admitted UpCloud bootstrap supplies this public key, never TOFU. */
export async function guestConnection(
    raw: unknown,
    rawProvider: unknown,
    keyLine: string,
) {
    const intent = Intent.pick({
        guest_node_id: true,
        node: true,
        ssh_identity: true,
    }).parse(raw);
    const provider = z.object({ address: z.ipv4() }).parse(rawProvider);
    if (intent.node.node_id !== intent.guest_node_id) {
        throw new Error("task_vm.enrollment_invalid");
    }
    const parts = keyLine.trim().split(/\s+/);
    if (parts[0] !== "ssh-ed25519" || !parts[1]) {
        throw new Error("task_vm.host_key_failed");
    }
    const blob = Uint8Array.from(atob(parts[1]), (c) => c.charCodeAt(0));
    if (blob.length !== 51) throw new Error("task_vm.host_key_failed");
    const digest = new Uint8Array(await crypto.subtle.digest("SHA-256", blob));
    const fingerprint = "SHA256:" +
        btoa(String.fromCharCode(...digest)).replace(/=+$/, "");
    const line = parts.slice(0, 2).join(" ");
    const target = {
        name: "target",
        host: intent.node.wireguard_ip,
        user: "orbit",
        port: 22,
        host_keys: [line],
    };
    return Guest.parse({
        pin: {
            ssh_host_key_type: "ssh-ed25519",
            ssh_host_key: parts[1],
            ssh_host_fingerprint: fingerprint,
            host_keys: [line],
        },
        ssh_execution: { ...intent.ssh_identity, target },
        public_ssh_execution: {
            ...intent.ssh_identity,
            target: { ...target, host: provider.address },
        },
    });
}

type Context = {
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};
export const behavior = {
    type: "@nckrtl/task-vm-ssh-input",
    version: "2026.11.01.1",
    resources: {
        guest: { schema: Guest, lifetime: "infinite", garbageCollection: 10 },
    },
    methods: {
        observe: {
            description:
                "Decode the trusted provider host observation and preserve an existing pin",
            arguments: z.object({
                intent: z.record(z.string(), z.unknown()),
                public_key: z.string(),
            }),
            async execute(
                args: { intent: Record<string, unknown>; public_key: string },
                ctx: Context,
            ) {
                const intent = z.object({
                    guest_node_id: Id,
                    node: z.object({ node_id: Id, wireguard_ip: z.ipv4() }),
                    ssh_identity: Ssh.omit({ target: true }),
                    ssh_execution: Ssh.nullable(),
                }).passthrough().parse(args.intent);
                const guest = await guestConnection(intent, {
                    address: intent.node.wireguard_ip,
                }, args.public_key);
                if (
                    intent.ssh_execution &&
                    intent.ssh_execution.target.host_keys[0] !==
                        guest.ssh_execution.target.host_keys[0]
                ) throw new Error("node.ssh_host_key_mismatch");
                return {
                    dataHandles: [
                        await ctx.writeResource("guest", "guest", guest),
                    ],
                };
            },
        },
    },
};
