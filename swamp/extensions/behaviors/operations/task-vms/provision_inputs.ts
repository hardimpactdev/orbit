import { z } from "npm:zod@4.4.3";
import { parse as parseYaml, stringify } from "jsr:@std/yaml@1.0.12";

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
    issuer_node_id: Id,
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
type Intent = z.infer<typeof ProvisionCommandIntent>;

/** Public data only: no host command, HTTP client, private read or status callback. */
export function provisionCommandInput(raw: unknown) {
    const input = ProvisionCommandIntent.parse(raw);
    const create = input.create;
    const config: Record<string, unknown> = create === null ? {} : {
        "cloud-init.user-data": "#cloud-config\n" + JSON.stringify({
            users: [{
                name: "orbit",
                lock_passwd: true,
                shell: "/bin/bash",
                sudo: "ALL=(ALL) NOPASSWD:ALL",
                ssh_authorized_keys: [create.gateway_public_key],
            }],
        }) + "\n",
    };
    const devices: Record<string, unknown> = create === null ? {} : {
        "cloud-init": { type: "disk", source: "cloud-init:config" },
    };
    if (create?.topology) {
        devices["topology-seed"] = {
            type: "disk",
            pool: create.pool,
            source: `${input.name}-topology-seed`,
        };
        config["raw.qemu.conf"] =
            '[device "qemu_balloon"]\nfree-page-reporting = "on"\n';
    }
    return {
        input_json: JSON.stringify(input),
        config_json: JSON.stringify({ config, devices }),
    };
}

const ListedVm = z.object({
    name: z.string(),
    type: z.literal("virtual-machine"),
    status: z.enum(["Running", "Stopped"]),
    config: z.record(z.string(), z.unknown()),
    state: z.object({
        network: z.record(
            z.string(),
            z.object({
                addresses: z.array(
                    z.object({ family: z.string(), address: z.string() }),
                ).default([]),
            }),
        ),
    }).nullable().optional(),
});
export function provisionVmObservation(
    raw: unknown,
    stdout: string,
    requireRunning = false,
) {
    const input = ProvisionCommandIntent.parse(raw);
    if (input.provider !== "incus" || input.vm === null) {
        throw new Error("Wrong provider observation");
    }
    const rows = z.array(z.object({ name: z.string() }).passthrough()).max(4096)
        .parse(JSON.parse(stdout)).filter((row) => row.name === input.name);
    if (rows.length > 1) throw new Error("Ambiguous provision VM");
    if (!rows.length) {
        if (requireRunning) throw new Error("task_vm.vm_missing");
        return { exists: false, running: false, address: null };
    }
    const vm = ListedVm.parse(rows[0]);
    if (vm.config["volatile.base_image"] !== input.image_fingerprint) {
        throw new Error("task_vm.image_mismatch");
    }
    const ip = (address: string) =>
        z.ipv4().parse(address).split(".").reduce(
            (n, p) => n * 256 + Number(p),
            0,
        );
    const [network, prefix] = input.vm.cidr.split("/");
    const base = ip(network), size = 2 ** (32 - Number(prefix));
    if (Number(prefix) < 16 || Number(prefix) > 28 || base % size !== 0) {
        throw new Error("Invalid VM subnet");
    }
    const addresses = Object.values(vm.state?.network ?? {}).flatMap((iface) =>
        iface.addresses
    )
        .filter((row) =>
            row.family === "inet" && z.ipv4().safeParse(row.address).success
        )
        .map((row) => row.address).filter((address) =>
            ip(address) >= base && ip(address) < base + size
        );
    if (
        addresses.length > 1 ||
        addresses.some((address) =>
            ip(address) <= base + 1 || ip(address) === base + size - 1
        )
    ) {
        throw new Error("Invalid provision address");
    }
    if (requireRunning && (vm.status !== "Running" || addresses.length !== 1)) {
        throw new Error("task_vm.not_settled");
    }
    return {
        exists: true,
        running: vm.status === "Running",
        address: addresses[0] ?? null,
    };
}

export function provisionMetadata(stdout: string): string {
    const metadata = z.object({ templates: z.record(z.string(), z.unknown()) })
        .passthrough().parse(parseYaml(stdout));
    metadata.templates = Object.fromEntries(
        Object.entries(metadata.templates).filter(([path]) =>
            !path.startsWith("/var/lib/cloud/seed/")
        ),
    );
    return stringify(metadata);
}

export function provisionSeedObservation(raw: unknown, stdout: string) {
    const input = ProvisionCommandIntent.parse(raw);
    const rows = z.array(z.object({ name: z.string(), type: z.string() })).max(
        8192,
    )
        .parse(JSON.parse(stdout)).filter((row) => row.type === "custom");
    const present = rows.some((row) =>
        row.name === `${input.name}-topology-seed`
    );
    if (
        !present && !rows.some((row) =>
            row.name ===
                `orbit-topology-seed-${input.image_fingerprint.slice(0, 12)}`
        )
    ) {
        throw new Error("task_vm.base_image_missing");
    }
    return { present };
}

const ProviderResult = z.object({
    provider_id: z.string().uuid().nullable(),
    address: z.ipv4(),
    image_fingerprint: z.string().min(1),
    running: z.literal(true),
    settled: z.literal(true),
});

/** Public provider output for the enclosing provision workflow. */
export function provisionProviderResult(raw: unknown, stdout: string) {
    const input = ProvisionCommandIntent.parse(raw);
    const observed = provisionVmObservation(input, stdout, true);
    return ProviderResult.parse({
        provider_id: null,
        address: observed.address,
        image_fingerprint: input.image_fingerprint,
        running: observed.running,
        settled: true,
    });
}

export const ProvisionTerminalArgs = z.object({
    intent: ProvisionCommandIntent,
    provider: ProviderResult,
    node: z.object({
        ssh_host_key_type: z.literal("ssh-ed25519"),
        ssh_host_key: z.string().regex(/^[A-Za-z0-9+/]+={0,2}$/),
        ssh_host_fingerprint: z.string().startsWith("SHA256:"),
        wireguard_public_key: z.string().regex(/^[A-Za-z0-9+/]{43}=$/),
        architecture: z.literal("x86_64").optional(),
    }),
    model: z.object({ task_vm_id: Id, issued: z.literal(true) }),
    process: z.object({ status: z.literal("active") }),
    worker_ready: z.literal(true),
    runtime_ready: z.literal(true),
});

/** Typed public fold only. The terminal command prints this JSON for the shared projector. */
export function provisionTerminalResult(raw: unknown) {
    const args = ProvisionTerminalArgs.parse(raw);
    if (
        args.model.task_vm_id !== args.intent.task_vm_id ||
        args.provider.image_fingerprint !== args.intent.image_fingerprint
    ) {
        throw new Error("task_vm.provision_refused");
    }
    return {
        result_json: JSON.stringify({
            task_vm_id: args.intent.task_vm_id,
            guest_node_id: args.intent.guest_node_id,
            ready: true,
            provider_id: args.provider.provider_id,
            provider: args.provider,
            enrollment: {
                ready: true,
                node: { ...args.node, address: args.provider.address },
                process: args.process,
            },
        }),
    };
}

type Context = {
    writeResource: (
        spec: string,
        name: string,
        value: unknown,
    ) => Promise<unknown>;
};
export const behavior = {
    type: "@nckrtl/task-vm-provision-inputs",
    version: "2026.10.31.1",
    resources: {
        input: {
            lifetime: "infinite",
            garbageCollection: 10,
            schema: z.object({
                input_json: z.string(),
                config_json: z.string(),
            }),
        },
        observation: {
            lifetime: "infinite",
            garbageCollection: 10,
            schema: z.object({
                exists: z.boolean(),
                running: z.boolean(),
                address: z.string().nullable(),
            }),
        },
        metadata: {
            lifetime: "infinite",
            garbageCollection: 10,
            schema: z.object({ metadata_yaml: z.string() }),
        },
        seed: {
            lifetime: "infinite",
            garbageCollection: 10,
            schema: z.object({ present: z.boolean() }),
        },
        provider: {
            lifetime: "infinite",
            garbageCollection: 10,
            schema: ProviderResult,
        },
        result: {
            lifetime: "infinite",
            garbageCollection: 10,
            schema: z.object({ result_json: z.string() }),
        },
    },
    methods: {
        read: {
            description:
                "Shape the full admitted public provision intent for command steps",
            arguments: z.object({ intent: ProvisionCommandIntent }),
            execute: async (args: { intent: Intent }, ctx: Context) => {
                const value = provisionCommandInput(args.intent);
                return {
                    dataHandles: [
                        await ctx.writeResource("input", "input", value),
                    ],
                };
            },
        },
        observation: {
            description:
                "Shape one exact public Incus observation without executing work",
            arguments: z.object({
                intent: ProvisionCommandIntent,
                stdout: z.string(),
                require_running: z.boolean().default(false),
            }),
            execute: async (
                args: {
                    intent: Intent;
                    stdout: string;
                    require_running: boolean;
                },
                ctx: Context,
            ) => {
                const value = provisionVmObservation(
                    args.intent,
                    args.stdout,
                    args.require_running,
                );
                return {
                    dataHandles: [
                        await ctx.writeResource(
                            "observation",
                            "observation",
                            value,
                        ),
                    ],
                };
            },
        },
        metadata: {
            description:
                "Remove cloud-init seed templates from public metadata",
            arguments: z.object({ stdout: z.string() }),
            execute: async (args: { stdout: string }, ctx: Context) => ({
                dataHandles: [
                    await ctx.writeResource("metadata", "metadata", {
                        metadata_yaml: provisionMetadata(args.stdout),
                    }),
                ],
            }),
        },
        provider: {
            description:
                "Shape the final exact Incus provider observation for its parent",
            arguments: z.object({
                intent: ProvisionCommandIntent,
                stdout: z.string(),
            }),
            execute: async (
                args: { intent: Intent; stdout: string },
                ctx: Context,
            ) => ({
                dataHandles: [
                    await ctx.writeResource(
                        "provider",
                        "provider",
                        provisionProviderResult(args.intent, args.stdout),
                    ),
                ],
            }),
        },
        seed: {
            description: "Shape exact immutable seed and clone inventory",
            arguments: z.object({
                intent: ProvisionCommandIntent,
                stdout: z.string(),
            }),
            execute: async (
                args: { intent: Intent; stdout: string },
                ctx: Context,
            ) => ({
                dataHandles: [
                    await ctx.writeResource(
                        "seed",
                        "seed",
                        provisionSeedObservation(args.intent, args.stdout),
                    ),
                ],
            }),
        },
        result: {
            description:
                "Shape the public provision result from native outputs",
            arguments: ProvisionTerminalArgs,
            execute: async (
                args: z.infer<typeof ProvisionTerminalArgs>,
                ctx: Context,
            ) => ({
                dataHandles: [
                    await ctx.writeResource(
                        "result",
                        "result",
                        provisionTerminalResult(args),
                    ),
                ],
            }),
        },
    },
};
