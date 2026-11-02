import { z } from "npm:zod@4.4.3";
import { ProvisionCommandIntent } from "./provision_input.ts";
import { Ssh } from "./input.ts";

const Intent = ProvisionCommandIntent.and(z.object({
    provider: z.literal("upcloud"),
    provider_id: z.uuid().nullable(),
    ssh_identity: Ssh.omit({ target: true }),
    bootstrap_host_key: z.string().startsWith("ssh-ed25519 "),
    upcloud: z.object({
        zone: z.string().min(1),
        plan: z.string().min(1),
        disk_gb: z.number().int().positive(),
        max_vms: z.number().int().positive(),
        source_template: z.string().min(1),
    }),
})).refine((v) => v.upcloud.source_template === v.image_fingerprint);
const Provider = z.object({
    provider_id: z.uuid(),
    address: z.ipv4(),
    image_fingerprint: z.string().min(1),
    running: z.literal(true),
    settled: z.literal(true),
    ssh_host_key: z.string().startsWith("ssh-ed25519 "),
}).strict();
type Context = {
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};

const Rows = z.object({
    servers: z.object({
        server: z.array(
            z.object({
                uuid: z.uuid(),
                title: z.string(),
                labels: z.object({
                    label: z.array(
                        z.object({ key: z.string(), value: z.string() }),
                    ),
                }).optional(),
            }).passthrough(),
        ),
    }),
});
/** The curl variable that carries the vault bootstrap private key into the body. */
export const BootstrapVariable = "ORBIT_BOOTSTRAP_PRIVATE_KEY";
const Placeholder = "@@ORBIT_BOOTSTRAP_PRIVATE_KEY@@";

/**
 * The create body as a curl --expand-data template. The private host key is
 * the only expansion: user_data is JSON inside a JSON string, so curl escapes
 * the vault value twice (`:json:json`). Everything else is public intent.
 */
export function createData(
    intent: z.infer<typeof Intent>,
    storageTier: string | null,
) {
    const cloud = intent.upcloud;
    const disk = {
        action: "clone",
        storage: cloud.source_template,
        title: intent.name + "-disk",
        size: cloud.disk_gb,
        ...(storageTier === null ? {} : { tier: storageTier }),
    };
    const user = {
        name: "orbit",
        lock_passwd: true,
        shell: "/bin/bash",
        sudo: "ALL=(ALL) NOPASSWD:ALL",
        ssh_authorized_keys: [intent.ssh_identity.public_key],
    };
    const config = {
        users: [user],
        disable_root: true,
        ssh_deletekeys: true,
        ssh_genkeytypes: [],
        ssh_keys: {
            ed25519_private: Placeholder,
            ed25519_public: intent.bootstrap_host_key,
        },
    };
    const body = JSON.stringify({
        server: {
            zone: cloud.zone,
            title: intent.name,
            hostname: intent.name,
            plan: cloud.plan,
            metadata: "yes",
            firewall: "off",
            remote_access_enabled: "no",
            labels: {
                label: [
                    { key: "orbit-task-vm", value: String(intent.task_vm_id) },
                    {
                        key: "orbit-task-vm-image",
                        value: cloud.source_template,
                    },
                ],
            },
            networking: {
                interfaces: {
                    interface: [{
                        type: "public",
                        ip_addresses: { ip_address: [{ family: "IPv4" }] },
                    }],
                },
            },
            storage_devices: { storage_device: [disk] },
            login_user: {
                username: "root",
                ssh_keys: { ssh_key: [intent.ssh_identity.public_key] },
            },
            user_data: "#cloud-config\n" + JSON.stringify(config) + "\n",
        },
    });
    // Public text must not open a curl expansion of its own.
    if (body.includes("{{") || body.split(Placeholder).length !== 2) {
        throw new Error("task_vm.upcloud_invalid");
    }
    return body.replace(Placeholder, `{{${BootstrapVariable}:json:json}}`);
}

export function cloudChoice(
    raw: unknown,
    stdout: string,
    plansStdout = "{}",
    existing = false,
) {
    const intent = Intent.parse(raw);
    const rows = Rows.parse(JSON.parse(stdout)).servers.server.filter((v) =>
        v.title === intent.name
    );
    if (rows.length > 1) throw new Error("task_vm.provider_identity_invalid");
    const row = rows[0];
    if (intent.provider_id !== null && row?.uuid !== intent.provider_id) {
        throw new Error("task_vm.provider_identity_invalid");
    }
    if (
        row?.labels &&
        !row.labels.label.some((v) =>
            v.key === "orbit-task-vm" && v.value === String(intent.task_vm_id)
        )
    ) throw new Error("task_vm.provider_identity_invalid");
    if (
        !row &&
        Rows.parse(JSON.parse(stdout)).servers.server.length >=
            intent.upcloud.max_vms
    ) throw new Error("task_vm.upcloud_budget_full");
    const plans = z.object({
        plans: z.object({
            plan: z.array(
                z.object({
                    name: z.string(),
                    storage_tier: z.string().optional(),
                }).passthrough(),
            ),
        }),
    }).parse(JSON.parse(plansStdout));
    const plan = plans.plans.plan.find((v) => v.name === intent.upcloud.plan);
    if (!plan) throw new Error("task_vm.upcloud_invalid");
    // After create the labelled server must exist; a missing one is a failed create.
    if (existing && !row) throw new Error("task_vm.upcloud_failed");
    const storageTier = plan.storage_tier ?? null;
    return {
        provider_id: row?.uuid ?? null,
        storage_tier: storageTier,
        create_data: row ? null : createData(intent, storageTier),
    };
}
export function cloudObservation(
    raw: unknown,
    stdout: string,
    providerId: string,
) {
    const intent = Intent.parse(raw);
    const row = z.object({
        server: z.object({
            uuid: z.uuid(),
            title: z.string(),
            hostname: z.string(),
            state: z.enum(["started", "stopped"]),
            labels: z.object({
                label: z.array(
                    z.object({ key: z.string(), value: z.string() }),
                ),
            }),
            ip_addresses: z.object({
                ip_address: z.array(
                    z.object({
                        access: z.string(),
                        family: z.string(),
                        address: z.string(),
                    }),
                ),
            }),
        }).passthrough(),
    }).parse(JSON.parse(stdout)).server;
    if (
        row.uuid !== z.uuid().parse(providerId) || row.title !== intent.name ||
        row.hostname !== intent.name ||
        !row.labels.label.some((v) =>
            v.key === "orbit-task-vm" && v.value === String(intent.task_vm_id)
        ) || !row.labels.label.some((v) =>
            v.key === "orbit-task-vm-image" &&
            v.value === intent.image_fingerprint
        )
    ) throw new Error("task_vm.provider_identity_invalid");
    const addresses = row.ip_addresses.ip_address.filter((v) =>
        v.access === "public" && v.family === "IPv4"
    );
    if (addresses.length !== 1) {
        throw new Error("task_vm.provider_identity_invalid");
    }
    return {
        provider_id: row.uuid,
        address: z.ipv4().parse(addresses[0].address),
        image_fingerprint: intent.image_fingerprint,
        state: row.state,
    };
}
export function cloudResult(raw: unknown, stdout: string, providerId: string) {
    const intent = Intent.parse(raw),
        observed = cloudObservation(raw, stdout, providerId);
    if (observed.state !== "started") throw new Error("task_vm.not_settled");
    return Provider.parse({
        provider_id: observed.provider_id,
        address: observed.address,
        image_fingerprint: observed.image_fingerprint,
        running: true,
        settled: true,
        ssh_host_key: intent.bootstrap_host_key,
    });
}

/** Public intent, curl request bodies and a typed provider receipt only. */
export const behavior = {
    type: "@nckrtl/task-vm-cloud-input",
    version: "2026.11.02.1",
    resources: {
        choice: {
            schema: z.object({
                provider_id: z.uuid().nullable(),
                storage_tier: z.string().nullable(),
                create_data: z.string().nullable(),
            }),
            lifetime: "infinite",
            garbageCollection: 10,
        },
        observation: {
            schema: z.object({
                provider_id: z.uuid(),
                address: z.ipv4(),
                image_fingerprint: z.string(),
                state: z.enum(["started", "stopped"]),
            }),
            lifetime: "infinite",
            garbageCollection: 10,
        },
        provider: {
            schema: Provider,
            lifetime: "infinite",
            garbageCollection: 10,
        },
    },
    methods: {
        choose: {
            description:
                "Select the exact admitted VM and plan; shape the create body",
            arguments: z.object({
                intent: Intent,
                stdout: z.string(),
                plans_stdout: z.string(),
                existing: z.boolean().default(false),
            }),
            async execute(
                args: {
                    intent: unknown;
                    stdout: string;
                    plans_stdout: string;
                    existing?: boolean;
                },
                ctx: Context,
            ) {
                return {
                    dataHandles: [
                        await ctx.writeResource(
                            "choice",
                            "choice",
                            cloudChoice(
                                args.intent,
                                args.stdout,
                                args.plans_stdout,
                                args.existing ?? false,
                            ),
                        ),
                    ],
                };
            },
        },
        observe: {
            description: "Validate the exact provider VM image and state",
            arguments: z.object({
                intent: Intent,
                stdout: z.string(),
                provider_id: z.uuid(),
            }),
            async execute(
                args: { intent: unknown; stdout: string; provider_id: string },
                ctx: Context,
            ) {
                return {
                    dataHandles: [
                        await ctx.writeResource(
                            "observation",
                            "observation",
                            cloudObservation(
                                args.intent,
                                args.stdout,
                                args.provider_id,
                            ),
                        ),
                    ],
                };
            },
        },
        result: {
            description: "Decode the actual provider image and public host pin",
            arguments: z.object({
                intent: Intent,
                stdout: z.string(),
                provider_id: z.uuid(),
            }),
            async execute(
                args: { intent: unknown; stdout: string; provider_id: string },
                ctx: Context,
            ) {
                return {
                    dataHandles: [
                        await ctx.writeResource(
                            "provider",
                            "provider",
                            cloudResult(
                                args.intent,
                                args.stdout,
                                args.provider_id,
                            ),
                        ),
                    ],
                };
            },
        },
    },
};
