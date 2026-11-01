import { z } from "npm:zod@4.4.3";
const Id = z.number().int().positive();
const Name = z.string().regex(/^[A-Za-z0-9][A-Za-z0-9-]{0,62}$/);
const Digest = z.string().regex(/^[a-f0-9]{64}$/);
const PublicKey = z.string().regex(
    /^(?:ssh-ed25519|ssh-rsa|ecdsa-sha2-nistp(?:256|384|521)) [A-Za-z0-9+/]+={0,3}(?: [!-~][ -~]*)?$/,
);
export const ProvisionCommandIntent = z.object({
    task_vm_id: Id,
    node_id: Id,
    host_node_id: Id.nullable(),
    guest_node_id: Id,
    gateway_node_id: Id,
    model_router_node_id: Id,
    provider: z.enum(["incus", "upcloud"]),
    name: Name,
    image_fingerprint: z.string().min(1),
    recipe_identity: z.string().regex(/^sha256:[a-f0-9]{64}$/),
    vm: z.object({ project: Name, name: Name, cidr: z.cidrv4() }).nullable(),
    create: z.object({
        image: Digest,
        expected_fingerprint: Digest,
        gateway_public_key: PublicKey,
        cpus: z.number().int().min(1).max(64),
        memory: z.string().regex(/^[1-9][0-9]{0,5}(?:MiB|GiB)$/),
        disk: z.string().regex(/^[1-9][0-9]{0,5}(?:MiB|GiB)$/),
        network: Name,
        pool: z.string().regex(/^[A-Za-z0-9][A-Za-z0-9_.-]{0,62}$/),
        topology: z.boolean(),
    }).nullable(),
}).passthrough().superRefine((input, ctx) => {
    if (
        input.name !== `tvm-${input.task_vm_id}` ||
        (input.provider === "incus" &&
            (input.host_node_id !== input.node_id ||
                input.vm?.name !== input.name ||
                input.create === null ||
                input.create.image !== input.image_fingerprint ||
                input.create.expected_fingerprint !==
                    input.image_fingerprint)) ||
        (input.provider === "upcloud" &&
            (input.node_id !== input.gateway_node_id ||
                input.host_node_id !== null ||
                input.vm !== null || input.create !== null))
    ) {
        ctx.addIssue({
            code: "custom",
            message: "Provision placement differs from its admitted VM",
        });
    }
});
